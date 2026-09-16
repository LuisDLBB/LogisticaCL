<?php

namespace App\Modules\ProviderPayments;

use App\Models\Client;
use App\Models\Coverage;
use App\Models\ServiceType;

class ParameterReview
{
    public function compare(array $source, ?int $tenantId): array
    {
        $clients = $tenantId ? Client::where('tenant_id', $tenantId)->where('is_active', true)->with('serviceTypes')->get() : collect();
        $coverages = $tenantId ? Coverage::where('tenant_id', $tenantId)->where('is_active', true)->get()->groupBy('commune_name') : collect();
        $services = ServiceType::where('is_active', true)->get()->keyBy('name');
        $clientsByMerchant = [];
        foreach ($clients as $client) {
            if ($client->source_merchant_name !== null && $client->source_merchant_name !== '') {
                $clientsByMerchant[$client->source_merchant_name][] = $client;
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
                    $action = trim($value) === ''
                        ? 'Peso vacío: se aplicará 1 por defecto. Falta configurar su equivalencia de peso transformado.'
                        : 'Definir la equivalencia a Peso_transformado entero. El maestro de transformación aún no está implementado.';
                } elseif (! $tenantId) {
                    $action = 'Pendiente de comparación: selecciona o registra la empresa propietaria.';
                } elseif ($category === 'clients') {
                    $matches = $clientsByMerchant[$value] ?? [];
                    if (count($matches) !== 1) {
                        $action = count($matches) > 1
                            ? 'Hay más de un cliente con este Comerciante (Pila). Debe quedar una sola coincidencia dentro de la empresa.'
                            : 'Ingresar este valor en Comerciante (Pila) de un cliente activo para obtener su RUT y razón social.';
                    }
                } elseif ($category === 'services') {
                    $service = $services->get($values[1]);
                    $matches = $clientsByMerchant[$value] ?? [];
                    if (! $service) {
                        $action = 'Crear o relacionar el servicio en el catálogo y asociarlo al cliente.';
                    } elseif (count($matches) !== 1) {
                        $action = 'Resolver primero el cliente para comprobar la asociación del servicio.';
                    } elseif (! $matches[0]->serviceTypes->contains(fn ($item) => $item->id === $service->id && $item->pivot->is_active)) {
                        $action = 'Asociar este servicio al cliente y activar la relación.';
                    }
                } elseif ($category === 'coverages') {
                    $matches = $coverages->get($value, collect());
                    if ($matches->isEmpty()) {
                        $action = 'Registrar una cobertura activa con esta comuna exactamente como viene en el archivo.';
                    } elseif ($matches->count() > 1) {
                        $action = 'Existen varias coberturas activas. Revisar vigencias y proveedor antes de elegir.';
                    } elseif (! $matches->first()->provider_id && ! $matches->first()->provider_tax_id) {
                        $action = 'Asignar proveedor o RUT de proveedor a la cobertura.';
                    }
                }
                if ($action !== null) {
                    $items[] = ['values' => $values, 'count' => $entry['count'], 'action' => $action];
                }
            }
            usort($items, fn ($a, $b) => $b['count'] <=> $a['count']);
            $groups[] = ['title' => $title, 'items' => $items, 'affected' => array_sum(array_column($items, 'count'))];
        }

        return $groups;
    }
}
