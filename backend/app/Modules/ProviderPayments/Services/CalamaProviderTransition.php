<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Provider;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CalamaProviderTransition
{
    public const VICTOR_RUT = '13013180-8';

    public const MARCELO_RUT = '13172671-6';

    public const VICTOR_MATRIX = 'Victor Robledo (Calama)';

    public const MARCELO_MATRIX = 'Marcelo Avendaño (Calama)';

    private const COMMUNES = ['calama', 'san pedro de atacama', 'sierra gorda', 'tocopilla'];

    private const COURIER_PROCESSES = ['variable', 'variables', 'lanas', 'retornos'];

    public function providerRut(string $period, string $process, ?string $commune, ?string $courier): ?string
    {
        if ($period < '202609' || ! in_array($this->normalized($commune), self::COMMUNES, true)) {
            return null;
        }

        if (in_array($this->normalized($process), self::COURIER_PROCESSES, true)
            && $this->normalized($courier) === 'victor robledo') {
            return self::VICTOR_RUT;
        }

        return self::MARCELO_RUT;
    }

    public function matrixFor(string $providerRut): string
    {
        return $providerRut === self::VICTOR_RUT ? self::VICTOR_MATRIX : self::MARCELO_MATRIX;
    }

    /** @param Collection<int, Provider> $providers */
    public function providerFor(Collection $providers, string $period, string $process, ?string $commune, ?string $courier, ?Provider $default): ?Provider
    {
        $rut = $this->providerRut($period, $process, $commune, $courier);
        if ($rut === null) {
            return $default;
        }

        $matches = $providers->filter(fn (Provider $provider): bool => strtoupper(preg_replace('/[^0-9K]/i', '', $provider->tax_id) ?? '') === strtoupper(preg_replace('/[^0-9K]/i', '', $rut) ?? ''));
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['file' => "Falta un proveedor único con RUT {$rut} para {$commune} en el período {$period}. Revisa Proveedores antes de cargar."]);
        }

        return $matches->first();
    }

    private function normalized(?string $value): string
    {
        return Str::of((string) $value)->squish()->lower()->ascii()->replaceMatches('/^cd\s+/', '')->toString();
    }
}
