<?php

namespace App\Modules\ProviderPayments;

use App\Models\Client;
use App\Models\Coverage;
use App\Models\ServiceType;
use App\Models\WeightTransformation;
use Illuminate\Support\Str;

class ParameterReview
{
    public function compare(array $source, ?int $tenantId): array
    {
        $clients = $tenantId ? Client::where('tenant_id', $tenantId)->where('is_active', true)->with('serviceTypes')->get() : collect();
        $coverages = $tenantId ? Coverage::where('tenant_id', $tenantId)->where('is_active', true)->get()->groupBy('commune_name') : collect();
        $coveragesByFoldedName = $coverages->flatten()->groupBy(fn (Coverage $coverage): string => $this->foldedKey($coverage->commune_name));
        $coveragesByComparableName = $coverages->flatten()->groupBy(fn (Coverage $coverage): string => $this->comparableKey($coverage->commune_name));
        $services = ServiceType::where('is_active', true)->get()->keyBy('name');
        $weightTransformations = $tenantId ? WeightTransformation::where('tenant_id', $tenantId)->where('is_active', true)->get()->groupBy('comparison_key') : collect();
        $clientsByMerchant = [];
        foreach ($clients as $client) {
            if ($client->source_merchant_name !== null && $client->source_merchant_name !== '') {
                $clientsByMerchant[$this->merchantKey($client->source_merchant_name)][] = $client;
            }
        }
        $groups = [];
        foreach (['clients' => 'Clientes', 'services' => 'Servicios por cliente', 'coverages' => 'Coberturas de comunas', 'weights' => 'Pesos'] as $category => $title) {
            $items = [];
            foreach ($source[$category] ?? [] as $entry) {
                $values = $entry['values'];
                $value = $values[0];
                $action = null;
                if ($category === 'weights') {
                    if (trim($value) !== '' && $weightTransformations->get($this->weightKey($value), collect())->isEmpty()) {
                        $action = 'Agregar este valor al maestro de pesos e indicar su Peso Transformado entero.';
                    }
                } elseif (! $tenantId) {
                    $action = 'Pendiente de comparación: selecciona o registra la empresa propietaria.';
                } elseif ($category === 'clients') {
                    $matches = $clientsByMerchant[$this->merchantKey($value)] ?? [];
                    if (count($matches) !== 1) {
                        $action = count($matches) > 1
                            ? 'Hay más de un cliente con este Comerciante (Pila). Debe quedar una sola coincidencia dentro de la empresa.'
                            : 'Ingresar este valor en Comerciante (Pila) de un cliente activo para obtener su RUT y razón social.';
                    }
                } elseif ($category === 'services') {
                    $service = $services->get($values[1]);
                    $matches = $clientsByMerchant[$this->merchantKey($value)] ?? [];
                    if (! $service) {
                        $action = 'Crear o relacionar el servicio en el catálogo y asociarlo al cliente.';
                    } elseif (count($matches) !== 1) {
                        $action = 'Resolver primero el cliente para comprobar la asociación del servicio.';
                    } elseif (! $matches[0]->serviceTypes->contains(fn ($item) => $item->id === $service->id && $item->pivot->is_active)) {
                        $action = 'Asociar este servicio al cliente y activar la relación.';
                    }
                } elseif ($category === 'coverages') {
                    $coverageValue = $this->coverageAlias($value);
                    $matches = $coverages->get($coverageValue, collect());
                    if ($matches->isEmpty()) {
                        $matches = $coveragesByFoldedName->get($this->foldedKey($coverageValue), collect());
                    }
                    if ($matches->isEmpty()) {
                        $matches = $coveragesByComparableName->get($this->comparableKey($coverageValue), collect());
                    }
                    if ($matches->isEmpty()) {
                        $action = 'Registrar una cobertura activa o un alias para esta comuna.';
                    } elseif ($matches->count() > 1) {
                        $action = 'Existen varias coberturas equivalentes para este nombre. Revisar proveedor, ruta y vigencia antes de elegir.';
                    } elseif (! $matches->first()->provider_id && ! $matches->first()->provider_tax_id) {
                        $action = 'Asignar proveedor o RUT de proveedor a la cobertura.';
                    }
                }
                if ($action !== null) {
                    $items[] = ['values' => $values, 'count' => $entry['count'], 'action' => $action];
                }
            }
            usort($items, fn ($a, $b) => $b['count'] <=> $a['count']);
            $groups[] = ['key' => $category, 'title' => $title, 'items' => $items, 'affected' => array_sum(array_column($items, 'count'))];
        }

        return $groups;
    }

    private function merchantKey(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }

    private function comparableKey(string $value): string
    {
        return Str::of($value)->squish()->lower()->ascii()->toString();
    }

    private function foldedKey(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }

    private function coverageAlias(string $value): string
    {
        return match ($this->foldedKey($value)) {
            '?u?oa' => 'Ñuñoa',
            'valpara?o' => 'Valparaíso',
            'chill?' => 'Chillán',
            'renaca' => 'REÑACA',
            'puerto aysen' => 'PUERTO AYSÉN',
            default => $value,
        };
    }

    private function weightKey(string $value): string
    {
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $matches)) {
            return rtrim(rtrim(number_format((float) str_replace(',', '.', $matches[0]), 6, '.', ''), '0'), '.');
        }

        return mb_strtolower(trim($value));
    }
}
