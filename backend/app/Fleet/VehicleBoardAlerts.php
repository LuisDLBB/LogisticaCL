<?php

namespace App\Fleet;

use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleBoardAlerts
{
    private ?Collection $verified = null;

    public function maintenanceRequired(Vehicle $vehicle, Collection $jobs, ?array $km = null): bool
    {
        $end = today('America/Santiago')->endOfWeek();
        $open = $jobs->whereIn('status', ['pending', 'scheduled']);
        $complete = fn ($job): bool => $job->status === 'scheduled' && $job->scheduled_at && ($job->execution_type === 'internal' ? filled($job->responsible_user_id) : filled($job->provider_id));
        if ($open->contains(fn ($job): bool => ! $complete($job) || $job->scheduled_at->toDateString() <= $end->toDateString())) {
            return true;
        }
        if ($open->isNotEmpty()) {
            return false;
        }

        return (bool) ($km && ! $km['in_workshop'] && ($km['maintenance_needed'] || ($km['remaining'] !== null && $km['remaining'] <= 1500)))
            || ($vehicle->next_maintenance_at && $vehicle->next_maintenance_at->lte($end));
    }

    public function paperwork(Vehicle $vehicle, int $days = 7): array
    {
        $this->verified ??= DB::table('VEH_document_vehicles')->get(['vehicle_id', 'details'])->groupBy('vehicle_id');
        $proofs = $this->verified->get($vehicle->id, collect())->map(fn ($link): array => json_decode($link->details, true) ?? []);
        $items = [];
        foreach (['technical_inspection_expires_at' => 'Revisión técnica', 'gas_certificate_expires_at' => 'Certificado de gases', 'circulation_permit_expires_at' => 'Permiso de circulación'] as $field => $label) {
            $date = $vehicle->$field;
            if (! $date) {
                continue;
            }
            if (in_array($field, ['technical_inspection_expires_at', 'gas_certificate_expires_at'], true) && ! $proofs->contains(fn (array $details): bool => ($details['verified_expirations'][$field]['date'] ?? null) === $date->toDateString())) {
                continue;
            }
            $remaining = (int) today('America/Santiago')->diffInDays(Carbon::parse($date->toDateString(), 'America/Santiago'), false);
            if ($remaining <= $days) {
                $items[] = ['field' => $field, 'label' => $label, 'date' => $date->toDateString(), 'display' => $date->format('d/m/Y'), 'days' => $remaining, 'expired' => $remaining < 0];
            }
        }

        return $items;
    }
}
