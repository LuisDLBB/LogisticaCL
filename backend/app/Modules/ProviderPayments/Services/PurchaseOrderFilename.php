<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Provider;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseOrderFilename
{
    public function __construct(private readonly PurchaseOrderAssigner $orders) {}

    /** @param array<string, mixed> $document */
    public function forDocument(array $document, string $extension): string
    {
        if (! in_array($extension, ['pdf', 'xlsx'], true)) {
            throw new InvalidArgumentException('La extensión del documento no es válida.');
        }

        $first = $document['rows']->first();
        $rut = preg_replace('/[^0-9K]/', '', strtoupper((string) $document['rut_proveedor']));
        $provider = Provider::query()->where('tenant_id', $first->tenant_id)
            ->where('tax_id_number', substr($rut, 0, -1))->first();
        $company = (string) $document['company_code'];
        $scope = $this->orders->concept($first);
        $stem = $provider?->ocFilenames()->where('company_code', $company)
            ->where('service_scope', $scope)->value('file_stem');

        if (blank($stem)) {
            throw ValidationException::withMessages([
                'oc' => "Falta el nombre de archivo de la OC {$document['oc']} para el proveedor {$document['rut_proveedor']}, empresa {$company}, servicio {$scope}. Agrégalo en Proveedores antes de descargar.",
            ]);
        }

        $types = $document['rows']->pluck('tipo_documento')
            ->map(fn (?string $type): string => mb_strtoupper(trim((string) $type)))
            ->unique()->values();
        $codes = $types->map(fn (string $type): string => match (true) {
            str_starts_with($type, 'FACTURA') => 'FAC',
            str_starts_with($type, 'BOLETA') => 'BOL',
            default => throw ValidationException::withMessages([
                'oc' => "El tipo de documento de la OC {$document['oc']} no permite crear un nombre de archivo.",
            ]),
        })->unique();
        if ($codes->count() !== 1) {
            throw ValidationException::withMessages([
                'oc' => "La OC {$document['oc']} mezcla facturas y boletas. Sepáralas antes de descargar.",
            ]);
        }

        return $document['oc'].'_'.$company.'_'.$codes->first().'_'.trim($stem).'.'.$extension;
    }
}
