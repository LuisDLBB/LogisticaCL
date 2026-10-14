<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VehicleDocumentImporter
{
    public function import(array $records, User $actor): void
    {
        $tenants = Tenant::all()->keyBy('code');
        DB::transaction(function () use ($records, $actor, $tenants): void {
            $vehicles = Vehicle::whereIn('plate', collect($records)->flatMap(fn (array $record): array => array_keys($record['vehicles']))->unique())->lockForUpdate()->get()->keyBy('plate');
            foreach ($records as $record) {
                $disk = Storage::disk('local');
                if (! $disk->exists($record['storage_path']) || hash_file('sha256', $disk->path($record['storage_path'])) !== $record['sha256']) {
                    throw new RuntimeException('PDF faltante o modificado: '.$record['filename']);
                }
                $existing = DB::table('VEH_documents')->where('sha256', $record['sha256'])->first();
                $id = $existing?->id ?? DB::table('VEH_documents')->insertGetId([
                    'tenant_id' => $record['tenant_code'] ? $tenants[$record['tenant_code']]->id : null,
                    'actor_user_id' => $actor->id, 'sha256' => $record['sha256'], 'kind' => $record['kind'],
                    'title' => $record['title'], 'filename' => $record['filename'], 'storage_path' => $record['storage_path'],
                    'policy_number' => $record['policy_number'], 'insurer' => $record['insurer'], 'starts_at' => $record['starts_at'],
                    'expires_at' => $record['expires_at'], 'details' => json_encode($record['details'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($existing) {
                    $enriched = (json_decode($existing->details, true) ?? []) + $record['details'];
                    if ($enriched !== (json_decode($existing->details, true) ?? [])) {
                        DB::table('VEH_documents')->where('id', $id)->update(['details' => json_encode($enriched, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                    }
                }
                foreach ($record['vehicles'] as $plate => $details) {
                    $vehicle = $vehicles->get($plate);
                    if (! $vehicle || ! app(VehicleCatalog::class)->owner($vehicle, $tenants)) {
                        throw new RuntimeException('Patente inexistente o propietario no verificado: '.$plate);
                    }
                    DB::table('VEH_document_vehicles')->insertOrIgnore([
                        'document_id' => $id, 'vehicle_id' => $vehicle->id, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $field = match ($record['kind']) {
                        'vehicle_policy' => 'insurance_expires_at',
                        'circulation_permit' => 'circulation_permit_expires_at',
                        default => null,
                    };
                    if ($field && $record['expires_at'] && (! $vehicle->$field || $vehicle->$field->toDateString() < $record['expires_at'])) {
                        $vehicle->update([$field => $record['expires_at']]);
                    }
                    if ($record['kind'] === 'circulation_permit') {
                        foreach (['Revisión técnica informada' => 'technical_inspection_expires_at', 'Gases informados' => 'gas_certificate_expires_at'] as $label => $dateField) {
                            $reported = $record['details'][$label] ?? null;
                            if ($reported !== null) {
                                $expiration = Carbon::createFromFormat('d/m/Y', $reported, 'America/Santiago');
                                if (! $vehicle->$dateField || $vehicle->$dateField->toDateString() < $expiration->toDateString()) {
                                    $vehicle->update([$dateField => $expiration->toDateString()]);
                                }
                            }
                        }
                    }

                }
            }
        });
    }
}
