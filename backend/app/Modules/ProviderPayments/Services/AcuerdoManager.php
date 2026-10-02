<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Acuerdo;
use App\Models\AcuerdoCalendarDay;
use App\Models\AcuerdoServiceRule;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcuerdoManager
{
    public function __construct(private readonly CalamaProviderTransition $transition) {}

    public function createCalendar(int $tenantId, string $period, array $holidays = []): void
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        $first = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
        $rows = [];
        foreach (range(1, $first->daysInMonth) as $day) {
            $date = $first->day($day)->toDateString();
            $rows[] = [
                'tenant_id' => $tenantId, 'periodo' => $period, 'fecha' => $date,
                'es_feriado' => in_array($date, $holidays, true), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('PPR_acuerdo_calendar_days')->insert($rows);
    }

    public function generate(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            if (Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
                throw ValidationException::withMessages(['periodo' => "El período {$period} ya tiene acuerdos. Puedes editarlos en la pantalla."]);
            }
            $sourcePeriod = Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', '<', $period)
                ->orderByDesc('periodo')->value('periodo');
            if ($sourcePeriod === null) {
                throw ValidationException::withMessages(['periodo' => 'Carga primero la planilla inicial de Acuerdos.']);
            }

            $rules = AcuerdoServiceRule::query()->where('tenant_id', $tenantId)->where('periodo', $sourcePeriod)->get();
            foreach ($rules as $rule) {
                if (in_array(mb_strtolower($rule->servicio), ['apoyo alza', 'agencia apoyo alza'], true)) {
                    continue;
                }
                AcuerdoServiceRule::create([
                    'tenant_id' => $tenantId, 'periodo' => $period, 'servicio' => $rule->servicio,
                    'modo' => $rule->modo, 'dias_semana' => $rule->dias_semana, 'cantidad_fija' => $rule->cantidad_fija,
                ]);
            }
            $this->createCalendar($tenantId, $period);

            $count = 0;
            $providers = Provider::query()->where('tenant_id', $tenantId)->get();
            Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $sourcePeriod)
                ->orderBy('id')->chunk(200, function (Collection $agreements) use ($tenantId, $period, $providers, &$count): void {
                    foreach ($agreements as $agreement) {
                        $data = $agreement->getAttributes();
                        $provider = $this->transition->providerFor($providers, $period, 'Acuerdos', $agreement->agencia, null, $providers->firstWhere('id', $agreement->provider_id));
                        $data['provider_id'] = $provider?->id;
                        $data['zona'] = ProviderZone::resolve($provider?->tax_id, $provider?->id, $data['zona'] ?? null);
                        unset($data['id'], $data['created_at'], $data['updated_at']);
                        $data['tenant_id'] = $tenantId;
                        $data['periodo'] = $period;
                        $data['nombre_proceso'] = $period.'-Acuerdos';
                        $data['source_agreement_id'] = $agreement->id;
                        $data['dias_calendario'] = 0;
                        $data['inasistencias'] = 0;
                        $data['adicionales'] = 0;
                        $data['cantidad'] = 0;
                        $data['total'] = 0;
                        $data['archivo_origen'] = null;
                        $data['hash_archivo'] = null;
                        $data['fila_origen'] = null;
                        $data['closed_at'] = null;
                        Acuerdo::create($data);
                        $count++;
                    }
                });
            $this->recalculate($tenantId, $period);

            return $count;
        });
    }

    public function recalculate(int $tenantId, string $period): void
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        if (Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)->whereNotNull('closed_at')->exists()) {
            throw ValidationException::withMessages(['periodo' => 'El período de Acuerdos está cerrado. Reábrelo con la clave maestra antes de recalcular.']);
        }
        $rules = AcuerdoServiceRule::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->get()->keyBy('servicio');
        $weekdayCounts = AcuerdoCalendarDay::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->where('es_feriado', false)->get()->countBy(fn (AcuerdoCalendarDay $day): int => $day->fecha->dayOfWeekIso);

        Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->orderBy('id')->chunk(200, function (Collection $agreements) use ($rules, $weekdayCounts): void {
                foreach ($agreements as $agreement) {
                    $rule = $rules->get($agreement->servicio);
                    if ($rule === null) {
                        throw ValidationException::withMessages(['servicio' => "No hay regla de calendario para «{$agreement->servicio}»."]);
                    }
                    $calendarDays = $rule->modo === 'fijo'
                        ? (int) $rule->cantidad_fija
                        : array_sum(array_map(fn (int $weekday): int => (int) ($weekdayCounts[$weekday] ?? 0), $rule->dias_semana ?? []));
                    $quantity = $calendarDays - $agreement->inasistencias + $agreement->adicionales;
                    if ($quantity < 0) {
                        throw ValidationException::withMessages(['inasistencias' => "Las inasistencias superan los días del acuerdo #{$agreement->id}."]);
                    }
                    $agreement->update([
                        'dias_calendario' => $calendarDays,
                        'cantidad' => $quantity,
                        'total' => $agreement->costo * $quantity * $agreement->factor,
                    ]);
                }
            });
    }
}
