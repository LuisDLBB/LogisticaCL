<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OperationRouteEstimator
{
    public function configured(): bool
    {
        return filled(config('services.openrouteservice.key'));
    }

    /** @return array{distance_km: float, duration_minutes: int, source: string} */
    public function estimate(string $origin, string $destination, bool $air = false, ?string $mapsUrl = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Configura la clave gratuita de openrouteservice antes de calcular kilómetros.');
        }

        if ($origin === $destination) {
            return ['distance_km' => 0.0, 'duration_minutes' => 0, 'source' => 'openrouteservice'];
        }

        $mapCoordinates = $mapsUrl ? $this->coordinatesFromMapsUrl($mapsUrl) : null;

        return Cache::remember('operations-route:'.hash('sha256', json_encode([$origin, $destination, $air, $mapCoordinates])), now()->addDays(30), function () use ($origin, $destination, $air, $mapCoordinates): array {
            $from = $mapCoordinates[0] ?? $this->coordinates($origin);
            $to = $mapCoordinates[1] ?? $this->coordinates($destination);

            if ($air) {
                $earthRadius = 6371.0;
                $latitudeDelta = deg2rad($to[1] - $from[1]);
                $longitudeDelta = deg2rad($to[0] - $from[0]);
                $haversine = sin($latitudeDelta / 2) ** 2
                    + cos(deg2rad($from[1])) * cos(deg2rad($to[1])) * sin($longitudeDelta / 2) ** 2;
                $distance = 2 * $earthRadius * asin(min(1.0, sqrt($haversine)));

                return ['distance_km' => round($distance, 1), 'duration_minutes' => (int) ceil($distance / 700 * 60 + 45), 'source' => 'estimacion_aerea'];
            }

            $response = Http::timeout(15)->withOptions(['verify' => config('services.openrouteservice.ca_bundle') ?: true])
                ->withHeaders(['Authorization' => (string) config('services.openrouteservice.key')])
                ->post('https://api.heigit.org/openrouteservice/v2/directions/driving-car', [
                    'coordinates' => [$from, $to],
                    'instructions' => false,
                ]);
            $summary = $response->json('routes.0.summary');
            if (! $response->successful() || ! is_array($summary) || ! isset($summary['distance'], $summary['duration'])) {
                throw new RuntimeException('El servicio de mapas no pudo calcular este tramo. Revisa las direcciones o ingresa los valores manualmente.');
            }

            return [
                'distance_km' => round((float) $summary['distance'] / 1000, 1),
                'duration_minutes' => (int) ceil((float) $summary['duration'] / 60),
                'source' => 'openrouteservice',
            ];
        });
    }

    /** @return array{0: float, 1: float} */
    private function coordinates(string $address): array
    {
        return Cache::remember('operations-geocode:v2:'.hash('sha256', $address), now()->addDays(30), function () use ($address): array {
            $response = Http::timeout(15)->withOptions(['verify' => config('services.openrouteservice.ca_bundle') ?: true])
                ->withHeaders(['Authorization' => (string) config('services.openrouteservice.key')])
                ->get('https://api.heigit.org/pelias/v1/search', [
                    'text' => $address.', Chile',
                    'boundary.country' => 'CHL',
                    'size' => 5,
                ]);
            $features = $response->json('features');
            if (! $response->successful() || ! is_array($features)) {
                throw new RuntimeException('No se pudo consultar la ubicación «'.$address.'». Intenta de nuevo más tarde.');
            }

            $addressParts = array_map('trim', explode(',', $address));
            $street = Str::lower(Str::ascii($addressParts[0] ?? ''));
            $commune = Str::lower(Str::ascii(end($addressParts) ?: ''));
            preg_match('/\b\d{2,6}\b/', $street, $number);
            $streetName = trim((string) preg_replace('/\b\d+\b/', '', $street));
            foreach ($features as $feature) {
                $label = Str::lower(Str::ascii((string) ($feature['properties']['label'] ?? '')));
                $coordinates = $feature['geometry']['coordinates'] ?? null;
                if (str_contains($label, $commune) && str_contains($label, $streetName)
                    && (! isset($number[0]) || preg_match('/\b'.preg_quote($number[0], '/').'\b/', $label))
                    && is_array($coordinates) && count($coordinates) >= 2) {
                    return [(float) $coordinates[0], (float) $coordinates[1]];
                }
            }

            throw new RuntimeException('No se pudo ubicar con precisión «'.$address.'». Guarda un enlace completo de Google Maps en este tramo o ingresa kilómetros y tiempo manualmente.');
        });
    }

    /** @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}|null */
    private function coordinatesFromMapsUrl(string $mapsUrl): ?array
    {
        $path = parse_url($mapsUrl, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        preg_match_all('/!2m2!1d(-?\d+(?:\.\d+)?)!2d(-?\d+(?:\.\d+)?)/', rawurldecode($path), $matches, PREG_SET_ORDER);
        if (count($matches) !== 2) {
            return null;
        }

        $coordinates = array_map(fn (array $match): array => [(float) $match[1], (float) $match[2]], $matches);
        foreach ($coordinates as [$longitude, $latitude]) {
            if (abs($longitude) > 180 || abs($latitude) > 90) {
                return null;
            }
        }

        return $coordinates;
    }
}
