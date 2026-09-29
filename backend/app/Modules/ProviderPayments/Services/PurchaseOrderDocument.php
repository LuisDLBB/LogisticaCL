<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PurchaseOrderDocument
{
    /** @return array<string, mixed> */
    public function load(int $tenantId, string $oc): array
    {
        $rows = DB::table('Maestro_Pagos')->where('tenant_id', $tenantId)
            ->where('oc', $oc)->orderBy('nombre_proceso')->orderBy('service_name')
            ->orderBy('fecha')->orderBy('seguimiento_paquete')->get();
        abort_if($rows->isEmpty(), 404);

        $first = $rows->first();
        abort_unless(DB::table('Cierres_Pagos')->where('tenant_id', $tenantId)
            ->where('periodo', $first->periodo)->exists(), 404);

        $companies = $rows->map(fn (object $row): string => $this->companyCode((string) $row->empresa_mandante))->unique();
        if ($companies->count() !== 1 || $rows->pluck('rut_proveedor')->unique()->count() !== 1) {
            throw ValidationException::withMessages(['oc' => 'La OC reúne empresas o proveedores distintos y requiere revisión.']);
        }

        $groups = $rows->groupBy(fn (object $row): string => json_encode([
            $row->nombre_proceso, $row->service_name ?: $row->tipo_pago,
        ], JSON_THROW_ON_ERROR))->map(function (Collection $payments): array {
            $first = $payments->first();

            return [
                'proceso' => (string) $first->nombre_proceso,
                'servicio' => (string) ($first->service_name ?: $first->tipo_pago),
                'cantidad' => $payments->count(),
                'valor_unitario' => $payments->pluck('valor')->unique()->count() === 1 ? (int) $first->valor : null,
                'total' => (int) $payments->sum('valor'),
            ];
        })->values();

        $provider = Provider::query()->where('tenant_id', $tenantId)
            ->when($first->provider_id, fn ($query) => $query->whereKey($first->provider_id),
                fn ($query) => $query->where('tax_id', $first->rut_proveedor))->first();
        $bank = $provider?->bankAccounts()->where('is_active', true)
            ->orderByDesc('is_primary')->orderBy('id')->first();
        $periodEnd = CarbonImmutable::createFromFormat('Ymd', $first->periodo.'01')->endOfMonth();
        $taxLines = $rows->groupBy(fn (object $row): string => $row->impuesto.'|'.$row->porcentaje_impuesto)
            ->map(function (Collection $payments): array {
                $first = $payments->first();

                return [
                    'impuesto' => (string) $first->impuesto,
                    'porcentaje' => (float) $first->porcentaje_impuesto,
                    'base' => (int) $payments->sum('valor'),
                    'valor' => (int) $payments->sum('valor_impuesto'),
                ];
            })->values();

        return [
            'oc' => $oc,
            'periodo' => (string) $first->periodo,
            'period_end' => $periodEnd,
            'company_code' => $companies->first(),
            'billing' => config('provider-payments.billing_companies.'.$companies->first()),
            'proveedor' => PurchaseOrderProviderName::display($first->rut_proveedor, $first->razon_social_proveedor),
            'rut_proveedor' => (string) $first->rut_proveedor,
            'nombre_operacional' => (string) ($provider?->operational_name ?: $rows->pluck('nombre_operacional')->filter()->first() ?: ''),
            'payment_terms' => (string) (($companies->first() === 'PMCB' && filled($provider?->payment_terms_pmcb))
                ? $provider->payment_terms_pmcb : ($provider?->payment_terms ?? '')),
            'zona' => (string) ($first->zona ?? ''),
            'tipo_documento' => $rows->pluck('tipo_documento')->unique()->implode(' / '),
            'impuesto' => $taxLines->count() === 1 ? (string) $first->impuesto : 'Mixto',
            'tax_lines' => $taxLines,
            'base' => (int) $rows->sum('valor'),
            'valor_impuesto' => (int) $rows->sum('valor_impuesto'),
            'valor_final_total' => (int) $rows->sum('valor_final_total'),
            'groups' => $groups,
            'rows' => $rows,
            'bank' => $bank ? [
                'titular' => $bank->account_holder_name ?: $provider->legal_name,
                'rut' => $bank->account_holder_tax_id ?: $provider->tax_id,
                'banco' => (string) $bank->bank_name,
                'tipo' => (string) $bank->account_type,
                'numero' => (string) $bank->account_number,
            ] : null,
        ];
    }

    public function address(?string $encrypted): string
    {
        if ($encrypted === null || $encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return $encrypted;
        }
    }

    private function companyCode(string $company): string
    {
        $name = strtoupper(trim($company));

        if (in_array($name, ['PMCB', 'PMBC'], true)) {
            return 'PMCB';
        }
        if (in_array($name, ['4N', '4 NORTES LOGISTICA SPA'], true)) {
            return '4N';
        }

        throw ValidationException::withMessages(['oc' => "No hay datos de facturación configurados para la empresa mandante {$company}."]);
    }
}
