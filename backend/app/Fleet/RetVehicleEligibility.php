<?php

namespace App\Fleet;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RetVehicleEligibility
{
    public const CLASSES = [
        'A1' => 'Taxis', 'A2' => 'Taxis, ambulancias y transporte de personas según límites legales',
        'A3' => 'Transporte de personas sin límite de asientos',
        'A4' => 'Carga simple con peso bruto superior a 3.500 kg',
        'A5' => 'Carga simple o articulada con peso bruto superior a 3.500 kg',
        'B' => 'Carga de hasta 3.500 kg de peso bruto; transporte particular según límites legales',
        'C' => 'Motocicletas y vehículos de dos o tres ruedas', 'D' => 'Maquinaria automotriz',
        'E' => 'Tracción animal', 'F' => 'Vehículos institucionales sujetos a requisitos especiales',
        'A1 ANTIGUA' => 'Transporte de personas; otorgada antes del 8 de marzo de 1997',
        'A2 ANTIGUA' => 'Carga; requiere verificar alcance de la licencia anterior a marzo de 1997',
    ];

    public function required(Vehicle $vehicle): ?string
    {
        $type = Str::of($vehicle->vehicle_type)->ascii()->lower()->toString();
        if (str_contains($type, 'moto')) {
            return 'C';
        }
        if (str_contains($type, 'tracto') || str_contains($type, 'articul')) {
            return 'A5';
        }
        if (! in_array($type, ['camion', 'camioneta', 'furgon', 'furgoneta', 'automovil'], true)) {
            return null;
        }
        // Imported weights outside this review threshold require master-data correction.
        if (! $vehicle->max_weight_kg || $vehicle->max_weight_kg > 100000) {
            return null;
        }

        return $vehicle->max_weight_kg > 3500 ? 'A4' : 'B';
    }

    public function compatible(User $driver, Vehicle $vehicle): bool
    {
        $required = $this->required($vehicle);
        if ($required === null) {
            return false;
        }
        $license = strtoupper(trim((string) $driver->license_type));
        // Historic licences are not silently treated as modern A2 cargo licences.
        if (str_contains($license, 'ANTIGUA')) {
            return $required === 'B';
        }
        preg_match_all('/(?<![A-Z0-9])(?:A\s*-?\s*[1-5]|[BCDEF])(?![A-Z0-9])/', $license, $matches);
        $classes = array_map(fn ($value) => preg_replace('/[\s-]/', '', $value), $matches[0]);

        return match ($required) {
            'B' => count(array_intersect($classes, ['B', 'A1', 'A2', 'A3', 'A4', 'A5'])) > 0,
            'A4' => count(array_intersect($classes, ['A4', 'A5'])) > 0,
            'A5' => in_array('A5', $classes, true),
            'C' => in_array('C', $classes, true),
            default => false,
        };
    }

    public function weightWarning(ServiceRequest $ret, Vehicle $vehicle): ?string
    {
        if ($ret->requested_weight_kg === null) {
            return 'El peso de la carga está pendiente de confirmar; no se puede comprobar la carga útil de este vehículo.';
        }
        if (! $vehicle->payload_capacity_kg) {
            return 'La carga útil del vehículo está pendiente de confirmar. El peso bruto del vehículo no equivale a su carga útil.';
        }
        if ($ret->requested_weight_kg > $vehicle->payload_capacity_kg) {
            return 'El peso informado de la carga supera la carga útil registrada del vehículo.';
        }

        return null;
    }

    public function assertLoad(ServiceRequest $ret, Vehicle $vehicle, bool $weightWarningAcknowledged = false): void
    {
        $capacity = $vehicle->capacity_m3 ?: ($vehicle->max_volume_liters ? $vehicle->max_volume_liters / 1000 : null);
        if ($ret->requested_volume_m3 !== null && (! $capacity || $ret->requested_volume_m3 > $capacity)) {
            throw ValidationException::withMessages(['vehicle_id' => 'El volumen solicitado supera la capacidad registrada o falta confirmar la capacidad en m³.']);
        }
        if (! $weightWarningAcknowledged && $ret->requested_weight_kg !== null && $this->weightWarning($ret, $vehicle) !== null) {
            throw ValidationException::withMessages(['vehicle_id' => 'La carga útil es insuficiente o está pendiente de confirmar. El peso bruto no es carga útil.']);
        }
    }
}
