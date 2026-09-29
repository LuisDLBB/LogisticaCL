<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Coverage;
use Illuminate\Support\Str;

class CourierSourcePartitioner
{
    private array $coverageProviders = [];

    public function shouldDiscardUnlocatedRoutePickup(string $service, string $address, string $commune): bool
    {
        return $this->key($service) === 'retiro en ruta'
            && trim($address) === ''
            && trim($commune) === '';
    }

    public function classify(string $merchant, string $recipientName, string $service = ''): string
    {
        if (in_array($this->key($merchant), [
            '(orquidea) hilanderia maisa',
            'comercial reginella ltda',
            'revesderecho',
        ], true)) {
            return 'lanas';
        }

        if ($this->key($merchant) === 'comercial peumo ltda'
            && $this->key($service) === 'servicio standar (v. trabajadores)') {
            return 'peumo';
        }

        return preg_match('/^desde\s+/iu', trim($recipientName)) === 1 ? 'retornos' : 'variables';
    }

    /** @return array{0: string, 1: string} */
    public function lanasDestination(int $tenantId, string $merchant, string $service, string $address, string $commune): array
    {
        $address = trim($address);
        $commune = trim($commune);
        if ($this->key($merchant) !== 'revesderecho' || ! in_array($this->key($service), [
            'servicio standar (mayorista)', 'standar (mayorista)',
        ], true)) {
            return [$address, $commune];
        }

        $originalCommune = $commune;
        $address = implode(' - ', array_filter([$address, $originalCommune], fn (string $part): bool => $part !== ''));

        return [$address, $this->preservesOriginalCommune($tenantId, $originalCommune) ? $originalCommune : 'CD QUILICURA'];
    }

    private function preservesOriginalCommune(int $tenantId, string $commune): bool
    {
        if (! isset($this->coverageProviders[$tenantId])) {
            $providers = [];
            $coverages = Coverage::query()->with('provider:id,tax_id,legal_name')
                ->where('tenant_id', $tenantId)->where('is_active', true)->get();
            foreach ($coverages as $coverage) {
                $key = $this->key((string) $coverage->commune_name);
                $rut = $this->rut((string) ($coverage->provider?->tax_id ?: $coverage->provider_tax_id));
                $name = $this->key((string) ($coverage->provider?->legal_name ?: $coverage->provider_name_source));
                $providers[$key][] = [$rut, $name];
            }
            $this->coverageProviders[$tenantId] = $providers;
        }

        foreach ($this->coverageProviders[$tenantId][$this->key($commune)] ?? [] as [$rut, $name]) {
            if ($rut === '761472224' || str_contains($name, 'transportes mandame')) {
                return true;
            }
            if ($this->key($commune) === 'curacavi' && ($rut === '772015259' || str_contains($name, 'ds group'))) {
                return true;
            }
        }

        return false;
    }

    private function rut(string $value): string
    {
        return preg_replace('/[^0-9K]/', '', strtoupper($value));
    }

    private function key(string $value): string
    {
        return Str::of($value)->squish()->lower()->ascii()->toString();
    }
}
