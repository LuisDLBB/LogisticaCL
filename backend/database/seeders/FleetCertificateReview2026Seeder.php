<?php

namespace Database\Seeders;

use App\Fleet\VehicleDocumentImporter;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FleetCertificateReview2026Seeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Revisión documental habilitada exclusivamente en localhost y pruebas.');
        }
        $records = json_decode(file_get_contents(database_path('data/fleet_certificate_review_2026.json')), true, 512, JSON_THROW_ON_ERROR);
        $actor = User::where('name', 'Hans de la Barra')->firstOrFail();
        DB::transaction(function () use ($records, $actor): void {
            app(VehicleDocumentImporter::class)->import($records, $actor);
            foreach ($records as $record) {
                $document = DB::table('VEH_documents')->where('sha256', $record['sha256'])->first();
                foreach ($record['vehicles'] as $plate => $metadata) {
                    $vehicle = Vehicle::where('plate', $plate)->lockForUpdate()->firstOrFail();
                    $link = DB::table('VEH_document_vehicles')->where('document_id', $document->id)->where('vehicle_id', $vehicle->id)->first();
                    $details = json_decode($link->details, true) ?? [];
                    if (isset($details['certificate_review_2026'])) {
                        continue;
                    }
                    $previous = [];
                    $updates = [];
                    foreach ($metadata['verified_expirations'] as $field => $expiration) {
                        if (! in_array($field, ['technical_inspection_expires_at', 'gas_certificate_expires_at'], true)) {
                            throw new RuntimeException('Campo documental no permitido.');
                        }
                        $previous[$field] = $vehicle->$field?->toDateString();
                        $updates[$field] = $expiration['date'];
                    }
                    $vehicle->forceFill($updates)->save();
                    $details = array_merge($details, $metadata, ['certificate_review_2026' => ['reviewed_at' => now()->toIso8601String(), 'actor_user_id' => $actor->id, 'previous_expirations' => $previous]]);
                    DB::table('VEH_document_vehicles')->where('id', $link->id)->update(['details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                }
            }
        });
    }
}
