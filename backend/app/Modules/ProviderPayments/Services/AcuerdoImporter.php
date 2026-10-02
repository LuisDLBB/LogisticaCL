<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Acuerdo;
use App\Models\AcuerdoServiceRule;
use App\Models\Client;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AcuerdoImporter
{
    private const HEADERS = [
        'proveedor', 'rut', 'agencia', 'tiposervicio', 'marca', 'servicio', 'costo',
        'qcalendario', 'qinasistencia', 'qadicionales', 'cantidad', 'glosafactor',
        'factor', 'total', 'razonsocialcliente', 'comerciantepila', 'rutcliente',
        'nombrecomercial', 'empresamandante',
    ];

    public function __construct(private readonly AcuerdoManager $manager, private readonly CalamaProviderTransition $transition) {}

    /** @return array{period: string, imported: int, existing: int, pending: int, total: int} */
    public function import(string $path, string $filename, int $tenantId): array
    {
        $book = IOFactory::load($path);
        try {
            $calendar = $book->getSheetByName('Calendario');
            $base = $book->getSheetByName('Base Acuerdos');
            if ($calendar === null || $base === null) {
                throw ValidationException::withMessages(['file' => 'La planilla debe tener las hojas «Calendario» y «Base Acuerdos».']);
            }
            $headers = [];
            for ($column = 1; $column <= 19; $column++) {
                $headers[] = $this->normalizeHeader($base->getCell([$column, 1])->getValue());
            }
            if ($headers !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => 'Las columnas de «Base Acuerdos» no coinciden con la plantilla adjunta.']);
            }

            $firstDate = $this->excelDate($calendar->getCell('A3')->getValue());
            if ($firstDate === null || $firstDate->day !== 1) {
                throw ValidationException::withMessages(['file' => 'La primera fecha del Calendario debe ser el día 1 del mes.']);
            }
            $period = $firstDate->format('Ym');
            MonthlyPaymentClosingService::assertOpen($tenantId, $period);
            $holidays = [];
            for ($day = 1; $day <= $firstDate->daysInMonth; $day++) {
                $flag = mb_strtolower(trim((string) $calendar->getCell([2, $day + 2])->getValue()));
                if (in_array($flag, ['x', '1', 'si', 'sí'], true)) {
                    $holidays[] = $firstDate->day($day)->toDateString();
                }
            }
            $rules = $this->rules($calendar);
            $allProviders = Provider::query()->where('tenant_id', $tenantId)->get();
            $providers = $allProviders->groupBy(fn (Provider $provider): string => $this->rut($provider->tax_id));
            $clients = Client::query()->where('tenant_id', $tenantId)->get()
                ->groupBy(fn (Client $client): string => $this->rut($client->tax_id));
            $hash = hash_file('sha256', $path);
            $rows = [];
            $pending = 0;
            for ($line = 2; $line <= $base->getHighestDataRow(); $line++) {
                $values = [];
                for ($column = 1; $column <= 19; $column++) {
                    $values[] = $base->getCell([$column, $line])->getValue();
                }
                if (collect($values)->every(fn (mixed $value): bool => $value === null || trim((string) $value) === '')) {
                    continue;
                }
                $service = trim((string) $values[5]);
                if ($service === '' || ! isset($rules[$service])) {
                    throw ValidationException::withMessages(['file' => "El servicio de la fila {$line} no está en la matriz del Calendario."]);
                }
                $providerName = trim((string) $values[0]);
                if ($providerName === '') {
                    throw ValidationException::withMessages(['file' => "Falta el proveedor en la fila {$line}."]);
                }
                $cost = $this->wholeNumber($values[6], $line, 'Costo');
                $absences = $this->wholeNumber($values[8] ?? 0, $line, 'Inasistencias');
                $additional = $this->wholeNumber($values[9] ?? 0, $line, 'Adicionales');
                $factor = $this->wholeNumber($values[12] ?? 1, $line, 'Factor');
                if ($factor < 1) {
                    throw ValidationException::withMessages(['file' => "El factor de la fila {$line} debe ser al menos 1."]);
                }
                $providerRut = trim((string) $values[1]);
                $clientRut = trim((string) $values[16]);
                $providerMatches = $providers->get($this->rut($providerRut), collect());
                $clientMatches = $clients->get($this->rut($clientRut), collect());
                $provider = $providerMatches->count() === 1 ? $providerMatches->first() : null;
                $provider = $this->transition->providerFor($allProviders, $period, 'Acuerdos', trim((string) $values[2]), null, $provider);
                $client = $clientMatches->count() === 1 ? $clientMatches->first() : null;
                $pending += $provider === null || $client === null ? 1 : 0;
                $rows[] = [
                    'tenant_id' => $tenantId, 'periodo' => $period,
                    'nombre_proceso' => $period.'-Acuerdos',
                    'proveedor_origen' => $providerName, 'rut_proveedor_origen' => $providerRut ?: null,
                    'provider_id' => $provider?->id,
                    'zona' => ProviderZone::resolve($provider?->tax_id ?: $providerRut, $provider?->id, null),
                    'agencia' => $this->text($values[2]), 'tipo_servicio' => $this->text($values[3]),
                    'marca' => $this->text($values[4]), 'servicio' => $service, 'costo' => $cost,
                    'dias_calendario' => 0, 'inasistencias' => $absences, 'adicionales' => $additional,
                    'cantidad' => 0, 'glosa_factor' => $this->text($values[11]), 'factor' => $factor, 'total' => 0,
                    'razon_social_cliente_origen' => $this->text($values[14]),
                    'comerciante_pila_origen' => $this->text($values[15]),
                    'rut_cliente_origen' => $clientRut ?: null,
                    'nombre_comercial_origen' => $this->text($values[17]),
                    'client_id' => $client?->id, 'empresa_mandante' => $this->text($values[18]),
                    'archivo_origen' => $filename, 'hash_archivo' => $hash, 'fila_origen' => $line,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'La hoja «Base Acuerdos» no contiene registros.']);
            }

            $existing = DB::transaction(function () use ($tenantId, $period, $hash, $rules, $holidays, $rows): int {
                $found = Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)->count();
                if ($found > 0) {
                    if (Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)->whereNotNull('closed_at')->exists()) {
                        throw ValidationException::withMessages(['file' => "El período {$period} está cerrado. Reábrelo con la clave maestra antes de volver a cargarlo."]);
                    }
                    $sameFile = Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                        ->where('hash_archivo', $hash)->count();
                    if ($found === count($rows) && $sameFile === $found) {
                        return $found;
                    }
                    throw ValidationException::withMessages(['file' => "El período {$period} ya tiene acuerdos. Edítalos aquí para evitar duplicados."]);
                }
                foreach ($rules as $service => $rule) {
                    AcuerdoServiceRule::create([
                        'tenant_id' => $tenantId, 'periodo' => $period, 'servicio' => $service,
                        'modo' => $rule['mode'], 'dias_semana' => $rule['weekdays'], 'cantidad_fija' => $rule['fixed'],
                    ]);
                }
                $this->manager->createCalendar($tenantId, $period, $holidays);
                foreach (array_chunk($rows, 250) as $chunk) {
                    DB::table('PPR_acuerdos')->insert($chunk);
                }
                $this->manager->recalculate($tenantId, $period);

                return 0;
            });

            return ['period' => $period, 'imported' => $existing === 0 ? count($rows) : 0,
                'existing' => $existing, 'pending' => $pending,
                'total' => (int) Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)->sum('total')];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** @return array<string, array{mode: string, weekdays: ?array, fixed: ?int}> */
    private function rules(Worksheet $calendar): array
    {
        $raw = [];
        for ($row = 3; $row <= $calendar->getHighestDataRow(); $row++) {
            $service = trim((string) $calendar->getCell([8, $row])->getValue());
            if ($service !== '') {
                if (in_array(mb_strtolower($service), ['apoyo alza', 'agencia apoyo alza'], true)) {
                    continue;
                }
                if (isset($raw[$service])) {
                    throw ValidationException::withMessages(['file' => "El servicio «{$service}» aparece dos veces en la matriz."]);
                }
                $raw[$service] = ['row' => $row, 'value' => $calendar->getCell([9, $row])->getValue()];
            }
        }
        if ($raw === []) {
            throw ValidationException::withMessages(['file' => 'La matriz de servicios del Calendario está vacía.']);
        }
        $byRow = collect($raw)->keyBy('row');
        $resolved = [];
        $resolve = function (int $row) use (&$resolve, &$resolved, $byRow): array {
            if (isset($resolved[$row])) {
                return $resolved[$row];
            }
            $entry = $byRow[$row] ?? null;
            if ($entry === null) {
                throw ValidationException::withMessages(['file' => "La matriz referencia una fila inexistente: {$row}."]);
            }
            $value = $entry['value'];
            if (is_numeric($value) && floor((float) $value) === (float) $value && (int) $value >= 0) {
                return $resolved[$row] = ['mode' => 'fijo', 'weekdays' => null, 'fixed' => (int) $value];
            }
            $formula = strtoupper(str_replace([' ', '$'], '', (string) $value));
            if (! preg_match('/^=\+?(?:(?:F[3-9]|I\d+)\+?)+$/', $formula)) {
                throw ValidationException::withMessages(['file' => "Fórmula no reconocida para «{$entry['row']}» en la matriz de servicios."]);
            }
            preg_match_all('/([FI])(\d+)/', $formula, $matches, PREG_SET_ORDER);
            $weekdays = [];
            foreach ($matches as $match) {
                if ($match[1] === 'F') {
                    $weekdays[] = (int) $match[2] - 2;
                } else {
                    $linked = $resolve((int) $match[2]);
                    if ($linked['mode'] !== 'dias_semana') {
                        throw ValidationException::withMessages(['file' => 'Una fórmula de días apunta a un servicio de cobro único.']);
                    }
                    $weekdays = [...$weekdays, ...$linked['weekdays']];
                }
            }

            return $resolved[$row] = ['mode' => 'dias_semana', 'weekdays' => array_values(array_unique($weekdays)), 'fixed' => null];
        };
        $rules = [];
        foreach ($raw as $service => $entry) {
            $rules[$service] = $resolve($entry['row']);
        }

        return $rules;
    }

    private function excelDate(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value)) {
            return null;
        }

        return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
    }

    private function wholeNumber(mixed $value, int $line, string $label): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        if (! is_numeric($value) || (float) $value < 0 || floor((float) $value) !== (float) $value) {
            throw ValidationException::withMessages(['file' => "{$label} inválido en la fila {$line}."]);
        }

        return (int) $value;
    }

    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $value))) ?? '';
    }

    private function rut(?string $value): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $value ?? '') ?? '');
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
