<?php

namespace App\Console\Commands;

use App\Fleet\VehicleBoardAlerts;
use App\Fleet\VehicleCatalog;
use App\Mail\VehiclePaperworkAlert;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class FleetDocumentAlerts extends Command
{
    protected $signature = 'fleet:document-alerts';

    protected $description = 'Avisar por correo documentos de vehículos por vencer o vencidos';

    public function handle(VehicleBoardAlerts $alerts, VehicleCatalog $catalog): int
    {
        if (in_array(config('mail.default'), ['log', 'array'], true) && ! app()->environment('testing')) {
            $this->warn('Correo sin transporte real configurado. No se registraron envíos.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('fleet-document-alerts', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        $failed = false;
        try {
            $tenants = Tenant::all();
            $settings = DB::table('VEH_document_alert_settings')->where('enabled', true)->get()->keyBy('tenant_id');
            foreach (Vehicle::query()->where('is_active', true)->lazyById(100) as $vehicle) {
                if (in_array(mb_strtolower(trim($vehicle->operational_status ?? '')), ['vendido', 'sold', 'robado', 'stolen'], true)) {
                    continue;
                }
                $owner = $catalog->owner($vehicle, $tenants);
                $setting = $owner ? $settings->get($owner->id) : null;
                if (! $setting) {
                    continue;
                }
                foreach ($alerts->paperwork($vehicle, $setting->warning_days) as $document) {
                    foreach (json_decode($setting->recipients, true) ?? [] as $recipient) {
                        $key = ['tenant_id' => $owner->id, 'vehicle_id' => $vehicle->id, 'document_field' => $document['field'], 'expires_at' => $document['date'], 'recipient' => $recipient];
                        if (DB::table('VEH_document_alert_deliveries')->where($key)->exists()) {
                            continue;
                        }
                        try {
                            Mail::to($recipient)->send(new VehiclePaperworkAlert($vehicle->plate, $owner->name, $document, route('fleet.vehicles.show', $vehicle)));
                            DB::table('VEH_document_alert_deliveries')->insert([...$key, 'sent_at' => now()]);
                        } catch (\Throwable $exception) {
                            report($exception);
                            $failed = true;
                            $this->error('No se pudo enviar el aviso de '.$vehicle->plate.'. Se reintentará.');
                        }
                    }
                }
            }
        } finally {
            $lock->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
