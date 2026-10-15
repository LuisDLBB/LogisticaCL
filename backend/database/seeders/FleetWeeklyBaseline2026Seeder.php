<?php

namespace Database\Seeders;

use App\Fleet\VehicleCatalog;
use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FleetWeeklyBaseline2026Seeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Esta carga de antecedentes está limitada a localhost y pruebas.');
        }
        $actor = User::query()->where('name', 'Hans de la Barra')->firstOrFail();
        $weeks = [
            '2026-08-31' => [
                ['KLLX31', 366921, 10000, 373602, '', '01/09/2026'],
                ['SGVB16', 271443, 10000, 274953, '', '01/09/2026'],
                ['SGSS86', 196116, 10000, 196092, '', '01/09/2026'],
                ['SPBC15', null, 10000, 0, 'TALLER', '01/09/2026'],
                ['STWJ98', 65016, 10000, 72523, '', '01/09/2026'],
                ['TFYF67', 324160, 10000, 327635, '', '01/09/2026'],
                ['TJHJ76', 151770, 10000, 153768, '', '01/09/2026'],
                ['TJGR16', 292495, 20000, 294980, '', '01/09/2026'],
                ['RVSK21', 269800, 15000, 277071, '', '01/09/2026'],
                ['VDSD30', 17473, 15000, 27513, 'Taller', '01/09/2026'],
                ['RPVT38', 420642, 10000, 421051, '', '01/09/2026'],
                ['TRZJ37', 173856, 20000, 180651, '', '01/09/2026'],
                ['PLRL48', 236537, 15000, 237456, '', '01/09/2026'],
                ['TFLJ42', 302940, 20000, 302963, '', '01/09/2026'],
                ['VBPD72', 197112, 20000, 199502, '', '01/09/2026'],
                ['PXGX13', 20000, null, 0, '', '01/09/2026'],
                ['PWZJ48', 40000, null, 0, '', '01/09/2026'],
                ['RKTL35', 357803, 40000, 385103, 'Taller', '01/09/2026'],
                ['SVRB44', 176957, 40000, 190424, '', '01/09/2026'],
                ['KLLX32', 324977, null, 0, 'VENDIDO', '01/09/2026'],
                ['TRWD80', 112511, null, 0, 'ROBADO', '01/09/2026'],
                ['PYWJ20', 190808, null, 0, 'VENDIDO', '01/09/2026'],
                ['TDTJ76', 60000, null, 0, 'VENDIDO', '01/09/2026'],
                ['TVHY33', 39643, null, 0, 'VENDIDO', '01/09/2026'],
            ],
            '2026-09-07' => [
                ['KLLX31', 366921, 10000, 375013, '', '07/09/2026'],
                ['SGVB16', 271443, 10000, 277590, '', '07/09/2026'],
                ['SGSS86', 196116, 10000, 196577, '', '07/09/2026'],
                ['SPBC15', null, 10000, 0, 'TALLER', '07/09/2026'],
                ['STWJ98', 76677, 10000, 76677, '', '07/09/2026'],
                ['TFYF67', 324160, 10000, 328926, '', '07/09/2026'],
                ['TJHJ76', 151770, 10000, 154335, '', '07/09/2026'],
                ['TJGR16', 292495, 20000, 299073, '', '07/09/2026'],
                ['RVSK21', 269800, 15000, 277844, '', '07/09/2026'],
                ['VDSD30', 17473, 15000, 27550, '', '07/09/2026'],
                ['RPVT38', 420642, 10000, 422170, '', '07/09/2026'],
                ['TRZJ37', 173856, 20000, 184229, '', '07/09/2026'],
                ['PLRL48', 236537, 15000, 238056, '', '07/09/2026'],
                ['TFLJ42', 302940, 20000, 304168, '', '07/09/2026'],
                ['VBPD72', 197112, 20000, 201641, '', '07/09/2026'],
                ['PXGX13', 20000, null, 0, '', '07/09/2026'],
                ['PWZJ48', 40000, null, 0, '', '07/09/2026'],
                ['RKTL35', 357803, 40000, 385103, 'Taller', '07/09/2026'],
                ['SVRB44', 176957, 40000, 190861, '', '07/09/2026'],
                ['KLLX32', 324977, null, 0, 'VENDIDO', '07/09/2026'],
                ['TRWD80', 112511, null, 0, 'ROBADO', '07/09/2026'],
                ['PYWJ20', 190808, null, 0, 'VENDIDO', '07/09/2026'],
                ['TDTJ76', 60000, null, 0, 'VENDIDO', '07/09/2026'],
                ['TVHY33', 39643, null, 0, 'VENDIDO', '07/09/2026'],
            ],
            '2026-09-14' => [
                ['KLLX31', 366921, 10000, 376489, '', '14/09/2026'],
                ['SGVB16', 271443, 10000, 279929, '', '14/09/2026'],
                ['SGSS86', 196116, 10000, 198070, '', '14/09/2026'],
                ['SPBC15', null, 10000, 0, 'TALLER', '14/09/2026'],
                ['STWJ98', 76677, 10000, 80887, '', '14/09/2026'],
                ['TFYF67', 324160, 10000, 329730, '', '14/09/2026'],
                ['TJHJ76', 151770, 10000, 154651, '', '14/09/2026'],
                ['TJGR16', 292495, 20000, 302960, '', '14/09/2026'],
                ['RVSK21', 269800, 15000, 278816, '', '14/09/2026'],
                ['VDSD30', 17473, 15000, 28018, '', '14/09/2026'],
                ['RPVT38', 420642, 10000, 422834, '', '14/09/2026'],
                ['TRZJ37', 173856, 20000, 187165, '', '14/09/2026'],
                ['PLRL48', 236537, 15000, 242158, '', '14/09/2026'],
                ['TFLJ42', 302940, 20000, 307272, '', '14/09/2026'],
                ['VBPD72', 197112, 20000, 202791, '', '14/09/2026'],
                ['PXGX13', 20000, null, 0, '', '14/09/2026'],
                ['PWZJ48', 40000, null, 0, '', '14/09/2026'],
                ['RKTL35', 357803, 40000, 0, 'Taller', '14/09/2026'],
                ['SVRB44', 176957, 40000, 191604, '', '14/09/2026'],
                ['KLLX32', 324977, null, 0, 'VENDIDO', '14/09/2026'],
                ['TRWD80', 112511, null, 0, 'ROBADO', '14/09/2026'],
                ['PYWJ20', 190808, null, 0, 'VENDIDO', '14/09/2026'],
                ['TDTJ76', 60000, null, 0, 'VENDIDO', '14/09/2026'],
                ['TVHY33', 39643, null, 0, 'VENDIDO', '14/09/2026'],
            ],
            '2026-09-21' => [
                ['KLLX31', 366921, 10000, 377535, '', '21/09/2026'],
                ['SGVB16', 281360, 10000, 281109, '', '21/09/2026'],
                ['SGSS86', 196116, 10000, 198775, '', '21/09/2026'],
                ['SPBC15', null, 10000, null, 'TALLER', '21/09/2026'],
                ['STWJ98', 85132, 10000, 84262, 'TALLER', '21/09/2026'],
                ['TFYF67', 324160, 10000, 331217, '', '21/09/2026'],
                ['TJHJ76', 151770, 10000, 155054, '', '21/09/2026'],
                ['TJGR16', 292495, 20000, 306089, '', '21/09/2026'],
                ['RVSK21', 269800, 15000, 279288, 'TALLER', '21/09/2026'],
                ['VDSD30', 17473, 15000, 28266, '', '21/09/2026'],
                ['RPVT38', 420642, 10000, 423085, '', '21/09/2026'],
                ['TRZJ37', 173856, 20000, 189370, '', '21/09/2026'],
                ['PLRL48', 236537, 15000, 243579, '', '21/09/2026'],
                ['TFLJ42', 302940, 20000, 309102, '', '21/09/2026'],
                ['VBPD72', 197112, 20000, 203857, '', '21/09/2026'],
                ['PXGX13', 20000, null, 0, '', '21/09/2026'],
                ['PWZJ48', 40000, null, 0, '', '21/09/2026'],
                ['RKTL35', 357803, 40000, null, 'Taller', '21/09/2026'],
                ['SVRB44', 176957, 40000, 191853, '', '21/09/2026'],
                ['KLLX32', 324977, null, 0, 'VENDIDO', '21/09/2026'],
                ['TRWD80', 112511, null, 0, 'ROBADO', '21/09/2026'],
                ['PYWJ20', 190808, null, 0, 'VENDIDO', '21/09/2026'],
                ['TDTJ76', 60000, null, 0, 'VENDIDO', '21/09/2026'],
                ['TVHY33', 39643, null, 0, 'VENDIDO', '21/09/2026'],
            ],
            '2026-09-28' => [
                ['KLLX31', 379523, 10000, 379523, '', '28/09/2026'],
                ['SGVB16', 281360, 10000, 0, 'Problemas de taquis', '28/09/2026'],
                ['SGSS86', 196116, 10000, 201899, '', '28/09/2026'],
                ['SPBC15', null, 10000, 0, 'TALLER', '28/09/2026'],
                ['STWJ98', 85132, 10000, 87165, '', '28/09/2026'],
                ['TFYF67', 336721, 10000, 335956, '', '28/09/2026'],
                ['TJHJ76', 151770, 10000, 156776, '', '28/09/2026'],
                ['TJGR16', 313316, 20000, 312203, '', '28/09/2026'],
                ['RVSK21', 269800, 15000, 0, 'TALLER', '28/09/2026'],
                ['VDSD30', 17473, 15000, 29031, '', '28/09/2026'],
                ['RPVT38', 420642, 10000, 423866, '', '28/09/2026'],
                ['TRZJ37', 173856, 20000, 191030, '', '28/09/2026'],
                ['PLRL48', 236537, 15000, 246014, '', '28/09/2026'],
                ['TFLJ42', 302940, 20000, 313436, '', '28/09/2026'],
                ['VBPD72', 197112, 20000, 0, 'TALLER', '28/09/2026'],
                ['PXGX13', 20000, null, 0, '', '28/09/2026'],
                ['PWZJ48', 40000, null, 0, '', '28/09/2026'],
                ['RKTL35', 357803, 40000, 0, 'Taller', '28/09/2026'],
                ['SVRB44', 176957, 40000, 193153, '', '28/09/2026'],
                ['KLLX32', 324977, null, 0, 'VENDIDO', '28/09/2026'],
                ['TRWD80', 112511, null, 0, 'ROBADO', '28/09/2026'],
                ['PYWJ20', 190808, null, 0, 'VENDIDO', '28/09/2026'],
                ['TDTJ76', 60000, null, 0, 'VENDIDO', '28/09/2026'],
                ['TVHY33', 39643, null, 0, 'VENDIDO', '28/09/2026'],
            ],
            '2026-10-05' => [
                ['KLLX31', 379523, 10000, 380520, '', '05/10/2026'],
                ['SGVB16', 281360, 10000, 283253, '', '05/10/2026'],
                ['SGSS86', 196116, 10000, 203097, '', '05/10/2026'],
                ['SPBC15', 345750, 10000, 345755, '', '05/10/2026'],
                ['STWJ98', 85132, 10000, 88020, '', '05/10/2026'],
                ['TFYF67', 336721, 10000, 339388, '', '05/10/2026'],
                ['TJHJ76', 151770, 10000, 158117, '', '05/10/2026'],
                ['TJGR16', 313316, 20000, 312203, '', '05/10/2026'],
                ['RVSK21', 269800, 15000, 0, 'TALLER', '05/10/2026'],
                ['VDSD30', 17473, 15000, 31284, '', '05/10/2026'],
                ['RPVT38', 420642, 10000, 425327, '', '05/10/2026'],
                ['TRZJ37', 173856, 20000, 192811, '', '05/10/2026'],
                ['PLRL48', 248738, 10000, 249063, '', '05/10/2026'],
                ['TFLJ42', 302940, 20000, 316898, '', '05/10/2026'],
                ['VBPD72', 197112, 20000, 0, 'TALLER', '05/10/2026'],
                ['PXGX13', 20000, null, 0, '', '05/10/2026'],
                ['PWZJ48', 40000, null, 0, '', '05/10/2026'],
                ['RKTL35', 357803, 40000, 0, 'Taller', '05/10/2026'],
                ['SVRB44', 176957, 40000, 193153, '', '05/10/2026'],
                ['KLLX32', 324977, null, 0, 'VENDIDO', '05/10/2026'],
                ['TRWD80', 112511, null, 0, 'ROBADO', '05/10/2026'],
                ['PYWJ20', 190808, null, 0, 'VENDIDO', '05/10/2026'],
                ['TDTJ76', 60000, null, 0, 'VENDIDO', '05/10/2026'],
                ['TVHY33', 39643, null, 0, 'VENDIDO', '05/10/2026'],
            ],
        ];
        $archived = ['KLLX32' => 'Vendido', 'TRWD80' => 'Robado', 'PYWJ20' => 'Vendido', 'TDTJ76' => 'Vendido', 'TVHY33' => 'Vendido'];
        $archivedBaselines = ['KLLX32' => 324977, 'TRWD80' => 112511, 'PYWJ20' => 190808, 'TDTJ76' => 60000, 'TVHY33' => 39643];
        $workshop = ['KLLX31', 'SPBC15', 'STWJ98', 'RVSK21', 'VBPD72', 'PWZJ48', 'RKTL35'];
        DB::transaction(function () use ($actor, $weeks, $archived, $archivedBaselines, $workshop): void {
            $plates = collect($weeks)->flatten(1)->map(fn ($row) => $row[0])->merge(array_keys($archived))->unique();
            $vehicles = Vehicle::query()->whereIn('plate', $plates)->lockForUpdate()->get()->keyBy('plate');
            if ($vehicles->count() !== $plates->count()) {
                throw new RuntimeException('Faltan patentes en el maestro; no se cargó ningún antecedente.');
            }
            $owners = Tenant::all()->keyBy('code');
            foreach ($weeks as $week => $entries) {
                foreach ($entries as [$plate, $baseline, $interval, $km, $notes, $sourceDate]) {
                    $vehicle = $vehicles[$plate];
                    $owner = app(VehicleCatalog::class)->owner($vehicle, $owners);
                    if (! $owner) {
                        throw new RuntimeException('Propietario no verificado para '.$plate);
                    }
                    $source = 'Planilla de fecha '.$sourceDate.' entregada por el usuario; fecha de realización de mantención no informada';
                    $key = 'weekly-baseline-2026:'.$week.':'.$plate;
                    DB::table('VEH_maintenance_parameters')->insertOrIgnore([
                        'vehicle_id' => $vehicle->id, 'tenant_id' => $owner->id, 'actor_user_id' => $actor->id, 'effective_from' => $week,
                        'interval_km' => $interval, 'source_key' => $key, 'reason' => 'KM recomendado informado en planilla '.$week.($interval === null ? ' · Sin intervalo positivo informado' : ''), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('VEH_maintenance_baselines')->insertOrIgnore([
                        'vehicle_id' => $vehicle->id, 'tenant_id' => $owner->id, 'actor_user_id' => $actor->id, 'observed_week' => $week,
                        'last_maintenance_km' => $baseline, 'source_key' => $key, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $issues = [];
                    if ($km === null) {
                        $issues[] = 'Kilometraje no informado: pendiente de verificar';
                    }
                    if ($km === 0) {
                        $issues[] = 'Lectura cero: pendiente de verificar';
                    }
                    if ($baseline !== null && $km > 0 && $km < $baseline) {
                        $issues[] = 'KM actual menor a la última mantención';
                    }
                    if ($baseline === null) {
                        $issues[] = 'Antecedente de última mantención no informado';
                    }
                    $accepted = $km > 0 && ($baseline === null || $km >= $baseline);
                    DB::table('VEH_weekly_readings')->insertOrIgnore([
                        'vehicle_id' => $vehicle->id, 'tenant_id' => $owner->id, 'actor_user_id' => $actor->id, 'week_start' => $week,
                        'odometer_km' => $km, 'last_maintenance_km' => $baseline, 'interval_km' => $interval, 'accepted' => $accepted,
                        'issues' => json_encode($issues, JSON_UNESCAPED_UNICODE), 'notes' => 'Fecha de planilla: '.$sourceDate.($notes !== '' ? ' · '.$notes : ''), 'source_key' => $key, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            foreach ($archivedBaselines as $plate => $baseline) {
                $vehicle = $vehicles[$plate];
                $owner = app(VehicleCatalog::class)->owner($vehicle, $owners);
                if (! $owner) {
                    throw new RuntimeException('Propietario no verificado para '.$plate);
                }
                DB::table('VEH_maintenance_baselines')->insertOrIgnore([
                    'vehicle_id' => $vehicle->id, 'tenant_id' => $owner->id, 'actor_user_id' => $actor->id,
                    'observed_week' => '2026-09-28', 'last_maintenance_km' => $baseline,
                    'source_key' => 'weekly-baseline-2026:2026-09-28:'.$plate,
                    'source' => 'Antecedente de planilla 28/09/2026 conservado en ficha de vehículo '.$archived[$plate].'; fecha de mantención no informada',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($vehicles as $vehicle) {
                $latest = DB::table('VEH_weekly_readings')->where('vehicle_id', $vehicle->id)->where('accepted', true)->orderByDesc('week_start')->orderByDesc('id')->first();
                if ($latest && ($vehicle->odometer_km === null || $latest->odometer_km >= $vehicle->odometer_km)) {
                    $vehicle->update(['odometer_km' => $latest->odometer_km]);
                }
                $desired = $archived[$vehicle->plate] ?? null;
                if ($desired === null && in_array($vehicle->plate, $workshop, true) && in_array(mb_strtolower($vehicle->operational_status), ['activa', 'available'], true)) {
                    $desired = 'En mantención';
                }
                if ($desired === null) {
                    continue;
                }
                $active = in_array($desired, ['Robado', 'Vendido'], true) ? false : $vehicle->is_active;
                if ($vehicle->operational_status === $desired && $vehicle->is_active === $active) {
                    continue;
                }
                $before = ['operational_status' => $vehicle->operational_status, 'is_active' => $vehicle->is_active, 'availability_reason' => $vehicle->availability_reason];
                $reason = 'Estado informado en planillas base 28/09/2026 y 05/10/2026';
                $vehicle->update(['operational_status' => $desired, 'is_active' => $active, 'availability_reason' => $reason]);
                $assigned = ServiceRequest::query()->where('vehicle_id', $vehicle->id)->whereDate('service_date', '>=', today('America/Santiago'))->whereIn('status', ['scheduled', 'rescheduled', 'reprogramming_pending'])->pluck('id')->all();
                DB::table('VEH_vehicle_status_events')->insert([
                    'vehicle_id' => $vehicle->id, 'tenant_id' => $owners[$vehicle->company_source]->id, 'actor_user_id' => $actor->id,
                    'target_state' => 'unavailable', 'reason' => $reason, 'before' => json_encode($before),
                    'after' => json_encode(['operational_status' => $desired, 'is_active' => $active, 'availability_reason' => $reason]),
                    'affected_request_ids' => json_encode($assigned), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
