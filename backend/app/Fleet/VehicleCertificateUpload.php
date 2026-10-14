<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VehicleCertificateUpload
{
    public function save(UploadedFile $file, Vehicle $vehicle, Tenant $owner, User $actor, VehicleCertificateReader $reader, VehicleCertificateParser $parser): string
    {
        $disk = Storage::disk('local');
        $hash = hash_file('sha256', $file->getRealPath());
        $path = 'fleet/documents/'.$hash.'.pdf';
        $existing = DB::table('VEH_documents')->where('sha256', $hash)->first();
        if ($existing && DB::table('VEH_document_vehicles')->where('document_id', $existing->id)->where('vehicle_id', $vehicle->id)->exists()) {
            return 'Este PDF ya está adjunto a la patente. No se modificaron las fechas.';
        }
        if (! $disk->exists($path)) {
            $file->storeAs('fleet/documents', $hash.'.pdf', 'local');
        }
        $reading = $reader->read($disk->path($path));
        $fields = $parser->parse($reading['pages'], $vehicle->plate);

        return DB::transaction(function () use ($file, $vehicle, $owner, $actor, $path, $hash, $reading, $fields): string {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $before = [];
            $updates = [];
            $accepted = [];
            foreach ($fields as $field => $value) {
                $latestIssue = DB::table('VEH_documents as d')->join('VEH_document_vehicles as l', 'd.id', '=', 'l.document_id')->where('l.vehicle_id', $vehicle->id)->get(['d.starts_at', 'l.details'])->filter(fn ($document) => isset(json_decode($document->details, true)['verified_expirations'][$field]))->max('starts_at');
                if (($latestIssue && $value['issued_at'] < substr($latestIssue, 0, 10)) || ($vehicle->$field && $value['date'] < $vehicle->$field->toDateString())) {
                    continue;
                }
                $before[$field] = $vehicle->$field?->toDateString();
                $updates[$field] = $value['date'];
                $accepted[$field] = $value;
            }
            $kind = count($fields) > 1 ? 'inspection_bundle' : (reset($fields)['type'] ?? 'other_document');
            $title = ['technical_inspection' => 'Revisión técnica', 'gas_certificate' => 'Emisiones contaminantes', 'homologation' => 'Homologación', 'inspection_bundle' => 'Revisión técnica y gases', 'other_document' => 'Documento adjunto'][$kind];
            $record = ['sha256' => $hash, 'storage_path' => $path, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'tenant_code' => $owner->code, 'kind' => $kind, 'title' => $title, 'policy_number' => null, 'insurer' => null, 'starts_at' => $fields ? min(array_column($fields, 'issued_at')) : null, 'expires_at' => $fields ? min(array_column($fields, 'date')) : null, 'details' => ['Estado de lectura' => $accepted ? 'Fechas actualizadas automáticamente' : 'Pendiente de revisión: no se pudo validar una fecha nueva para esta patente.', 'Lectura' => $reading['method'], 'Vencimiento impreso' => implode(' / ', array_column($fields, 'display'))], 'vehicles' => [$vehicle->plate => ['page' => reset($fields)['page'] ?? 1, 'verified_expirations' => $accepted, 'upload_audit' => ['is_test' => app()->environment(['local', 'testing']) && config('fleet.auto_test_mode'), 'actor_user_id' => $actor->id, 'recorded_at' => now()->toIso8601String(), 'previous_expirations' => $before]]]];
            app(VehicleDocumentImporter::class)->import([$record], $actor);
            if ($updates) {
                $vehicle->forceFill($updates)->save();
            }

            return $accepted ? 'PDF guardado. Fechas y alertas actualizadas automáticamente.' : 'PDF guardado y visible. Lectura pendiente de revisión; las fechas actuales se conservaron.';
        });
    }
}
