<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Operations\Services\OperationDriverJourneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsDriverJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Storage::fake('local');
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $supervisor = User::factory()->create(['profile_name' => 'Administrador']);
        $supervisor->tenants()->attach($tenant->id, ['role_code' => 'supervisor', 'is_active' => true]);
        $driver = User::factory()->create(['name' => 'Chofer Prueba', 'profile_name' => 'Chofer']);
        $driver->tenants()->attach($tenant->id, ['role_code' => 'driver', 'is_active' => true]);
        $driverId = DB::table('Ope_Choferes')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $driver->id,
            'rut' => '99.999.999-9', 'name' => 'Chofer Prueba', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$tenant->id, $supervisor, $driver, $driverId];
    }

    private function guide(int $tenant, int $user, int $order, string $name, string $kind = 'trunk'): int
    {
        $load = DB::table('Ope_Cargas')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $user, 'source_type' => 'master',
            'filename' => "master-{$order}.xlsx", 'path' => "tests/{$order}", 'sha256' => hash('sha256', "master-{$order}"),
            'sheet' => 'Datos', 'mapping' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lot = DB::table('Ope_Lotes')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $user, 'master_load_id' => $load,
            'operation_date' => '2026-10-09', 'name' => 'Proceso prueba', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $origin = DB::table('Ope_Ubicaciones')->insertGetId([
            'tenant_id' => $tenant, 'name' => 'Bodega', 'address' => 'Galvarino 8481',
            'commune' => 'Quilicura', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destination = DB::table('Ope_Ubicaciones')->insertGetId([
            'tenant_id' => $tenant, 'name' => $name, 'address' => "Calle {$order}",
            'commune' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $departure = DB::table('Ope_ProgramacionSalidas')->insertGetId([
            'lot_id' => $lot, 'departure_date' => '2026-10-09', 'name' => $name,
            'role' => 'troncal', 'origin_id' => $origin, 'destination_id' => $destination,
            'plate' => 'ABCD12', 'driver_name' => 'Chofer Prueba', 'driver_rut' => '99.999.999-9',
            'version' => 1, 'status' => 'approved', 'approved_by' => $user, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = json_encode([
            'departure' => ['departure_date' => '2026-10-09', 'plate' => 'ABCD12', 'driver_name' => 'Chofer Prueba', 'driver_rut' => '99.999.999-9'],
            'destination' => ['name' => $name, 'address' => "Calle {$order}", 'commune' => $name],
            'agencies' => [['configuration' => ['transport_kind' => $kind, 'transport_id' => $kind === 'trunk' ? 999 : 998, 'stop_order' => $order], 'transport' => ['name' => $kind === 'trunk' ? 'Troncal de prueba' : 'Posta de prueba']]],
            'count' => 3,
        ], JSON_THROW_ON_ERROR);
        DB::table('Ope_Guias')->insert([
            'departure_id' => $departure, 'version' => 1, 'snapshot' => $snapshot,
            'sha256' => hash('sha256', $snapshot), 'approved_by' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $departure;
    }

    public function test_driver_takes_one_route_with_guides_sorted_by_stop_and_cannot_open_staff_module(): void
    {
        [$tenant, $supervisor, $driver, $driverId] = $this->context();
        $second = $this->guide($tenant, $supervisor->id, 2, 'Rancagua');
        $first = $this->guide($tenant, $supervisor->id, 1, 'Buin');
        $this->actingAs($driver)->get('/operaciones')->assertForbidden();
        $this->get('/inicio')->assertOk()->assertSee('Mi Ruta');
        $this->get('/operaciones/mi-ruta?date=2026-10-09')->assertOk()->assertSee('Troncal de prueba')->assertSee('Buin')->assertSee('Rancagua');

        $record = DB::table('Ope_Choferes')->where('id', $driverId)->firstOrFail();
        $key = app(OperationDriverJourneyService::class)->assigned($tenant, $record, '2026-10-09')->first()['key'];
        $this->post(route('operations.driver.claim'), ['date' => '2026-10-09', 'key' => $key])->assertRedirect();
        $journey = DB::table('Ope_Recorridos')->firstOrFail();
        $this->assertSame([$first, $second], DB::table('Ope_RecorridoParadas')->orderBy('sequence')->pluck('departure_id')->all());
        $this->post(route('operations.driver.claim'), ['date' => '2026-10-09', 'key' => $key])->assertSessionHasErrors('route');
        $this->get(route('operations.driver.show', $journey->id))->assertOk()->assertSee('Buin')->assertSee('Rancagua');
        $guideIds = DB::table('Ope_RecorridoParadas')->orderBy('sequence')->pluck('guide_id');
        foreach ($guideIds as $index => $guideId) {
            DB::table('Ope_GuiasBsale')->insert([
                'tenant_id' => $tenant, 'guide_id' => $guideId, 'version' => 1,
                'estado' => 'generada', 'numero' => 'BS-'.($index + 1),
                'url_pdf' => 'https://www.bsale.cl/test.pdf', 'url_publica' => 'https://www.bsale.cl/test',
                'user_id' => $supervisor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->get(route('operations.driver.guides', $journey->id))->assertOk()->assertSee('BS-1')->assertSee('BS-2')->assertSee('Ver PDF');
        $other = User::factory()->create(['profile_name' => 'Chofer']);
        $other->tenants()->attach($tenant, ['role_code' => 'driver', 'is_active' => true]);
        DB::table('Ope_Choferes')->insert([
            'tenant_id' => $tenant, 'user_id' => $other->id, 'rut' => '77.777.777-7',
            'name' => 'Otro chofer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($other)->get(route('operations.driver.guides', $journey->id))->assertNotFound();
    }

    public function test_driver_records_journey_photo_signature_returns_and_transfer_with_tenant_isolation(): void
    {
        [$tenant, $supervisor, $driver, $driverId] = $this->context();
        $this->guide($tenant, $supervisor->id, 1, 'Buin');
        $record = DB::table('Ope_Choferes')->where('id', $driverId)->firstOrFail();
        $key = app(OperationDriverJourneyService::class)->assigned($tenant, $record, '2026-10-09')->first()['key'];
        $this->actingAs($driver)->post(route('operations.driver.claim'), ['date' => '2026-10-09', 'key' => $key]);
        $journey = DB::table('Ope_Recorridos')->firstOrFail();
        $stop = DB::table('Ope_RecorridoParadas')->firstOrFail();

        $this->post(route('operations.driver.start', $journey->id), [
            'plate' => 'WRONG', 'start_odometer' => 100, 'latitude' => -33.4, 'longitude' => -70.7,
            'vehicle_no_observations' => 1,
        ])->assertSessionHasErrors('plate');
        $this->post(route('operations.driver.start', $journey->id), [
            'plate' => 'AB-CD-12', 'start_odometer' => 100, 'latitude' => -33.4, 'longitude' => -70.7,
            'vehicle_no_observations' => 1, 'vehicle_photos' => [UploadedFile::fake()->image('vehicle.jpg')],
        ])->assertSessionHasNoErrors();
        $this->assertSame('gps', DB::table('Ope_Recorridos')->where('id', $journey->id)->value('start_location_source'));
        $this->post(route('operations.driver.depart', [$journey->id, $stop->id]))->assertSessionHasNoErrors();
        $this->post(route('operations.driver.arrive', [$journey->id, $stop->id]), ['latitude' => -33.5, 'longitude' => -70.6])->assertSessionHasNoErrors();
        $this->post(route('operations.driver.complete', [$journey->id, $stop->id]), [
            'return_count' => 2, 'signed_guide_photo' => UploadedFile::fake()->image('guide.jpg'),
            'return_photo' => UploadedFile::fake()->image('returns.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('completed', DB::table('Ope_RecorridoParadas')->where('id', $stop->id)->value('status'));
        $this->assertSame(0, DB::table('Ope_RecorridoTraspasos')->count());
        $this->assertSame(3, DB::table('Ope_RecorridoEvidencias')->where('journey_id', $journey->id)->count());
        $this->post(route('operations.driver.finish', $journey->id), ['end_odometer' => 90])->assertSessionHasErrors('end_odometer');
        $this->post(route('operations.driver.finish', $journey->id), ['end_odometer' => 145])->assertSessionHasNoErrors();

        $recipient = User::factory()->create(['profile_name' => 'Chofer']);
        $recipient->tenants()->attach($tenant, ['role_code' => 'driver', 'is_active' => true]);
        $recipientId = DB::table('Ope_Choferes')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $recipient->id, 'rut' => '88.888.888-8',
            'name' => 'Receptor', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post(route('operations.driver.transfers.store', $journey->id), [
            'to_driver_id' => $recipientId, 'package_count' => 2, 'request_key' => '3af3f997-141d-453b-92de-5428124192dc',
        ])->assertSessionHasNoErrors();
        $this->post(route('operations.driver.transfers.store', $journey->id), [
            'to_driver_id' => $recipientId, 'package_count' => 2, 'request_key' => '3af3f997-141d-453b-92de-5428124192dc',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('Ope_RecorridoTraspasos')->count());
        $this->actingAs($recipient)->get(route('operations.driver.show', $journey->id))->assertNotFound();

        $recipientJourney = DB::table('Ope_Recorridos')->insertGetId([
            'tenant_id' => $tenant, 'driver_id' => $recipientId, 'departure_date' => '2026-10-09',
            'transport_kind' => 'post', 'transport_id' => 777, 'name' => 'Posta receptora',
            'plate' => 'WXYZ88', 'status' => 'in_progress', 'start_odometer' => 10,
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $transfer = DB::table('Ope_RecorridoTraspasos')->firstOrFail();
        $this->post(route('operations.driver.transfers.receive', $transfer->id), ['journey_id' => $recipientJourney])->assertSessionHasNoErrors();
        $this->post(route('operations.driver.transfers.receive', $transfer->id), ['journey_id' => $recipientJourney])->assertSessionHasNoErrors();
        $this->assertSame(2, app(OperationDriverJourneyService::class)->returnBalance($recipientJourney)['available']);

        $third = User::factory()->create(['profile_name' => 'Chofer']);
        $third->tenants()->attach($tenant, ['role_code' => 'driver', 'is_active' => true]);
        $thirdId = DB::table('Ope_Choferes')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $third->id, 'rut' => '55.555.555-5',
            'name' => 'Tercer chofer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post(route('operations.driver.transfers.store', $recipientJourney), [
            'to_driver_id' => $thirdId, 'package_count' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, app(OperationDriverJourneyService::class)->returnBalance($recipientJourney)['available']);

        DB::table('Ope_Recorridos')->where('id', $recipientJourney)->update(['status' => 'completed', 'finished_at' => now()]);
        $warehouseKey = '714e24b6-f341-4ad8-9929-39c3b7476e7a';
        $this->post(route('operations.driver.warehouse.store', $recipientJourney), [
            'package_count' => 1, 'latitude' => -33.3, 'longitude' => -70.7,
            'photo' => UploadedFile::fake()->image('bodega.jpg'), 'request_key' => $warehouseKey,
        ])->assertSessionHasNoErrors();
        $this->post(route('operations.driver.warehouse.store', $recipientJourney), [
            'package_count' => 1, 'latitude' => -33.3, 'longitude' => -70.7,
            'photo' => UploadedFile::fake()->image('bodega-again.jpg'), 'request_key' => $warehouseKey,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('Ope_RecorridoDevolucionesBodega')->count());
        $this->assertSame(0, app(OperationDriverJourneyService::class)->returnBalance($recipientJourney)['available']);
        $this->post(route('operations.driver.warehouse.store', $recipientJourney), [
            'package_count' => 1, 'latitude' => -33.3, 'longitude' => -70.7,
            'photo' => UploadedFile::fake()->image('too-many.jpg'),
        ])->assertSessionHasErrors('package_count');
        $receipt = DB::table('Ope_RecorridoDevolucionesBodega')->firstOrFail();
        $this->get(route('operations.driver.warehouse.photo', [$recipientJourney, $receipt->id]))->assertOk();
        $this->actingAs($third)->get(route('operations.driver.warehouse.photo', [$recipientJourney, $receipt->id]))->assertForbidden();
    }

    public function test_supervisor_can_enable_driver_without_email_and_driver_gets_limited_access(): void
    {
        [$tenant, $supervisor, $driver] = $this->context();
        $unlinked = DB::table('Ope_Choferes')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => null, 'rut' => '66.666.666-6',
            'name' => 'Chofer Proveedor', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($driver)->get(route('operations.drivers.access.index'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('operations.drivers.access.index'))->assertOk()->assertSee('Chofer Proveedor');
        $this->post(route('operations.drivers.access.store', $unlinked), [
            'username' => 'proveedor.ruta', 'password' => 'ClaveSegura12345', 'password_confirmation' => 'ClaveSegura12345',
        ])->assertSessionHasNoErrors();
        $accountId = DB::table('Ope_Choferes')->where('id', $unlinked)->value('user_id');
        $account = User::findOrFail($accountId);
        $this->assertNull($account->email);
        $this->assertTrue(password_verify('ClaveSegura12345', $account->password));
        $this->flushSession();
        $this->actingAs($account)->get('/inicio')->assertSee('Abrir mi ruta de hoy');
        $this->get('/operaciones')->assertForbidden();
        $this->get(route('operations.driver.index'))->assertOk();
    }

    public function test_manual_coordinates_are_limited_to_local_private_network_and_marked_in_records(): void
    {
        [$tenant, $supervisor, $driver, $driverId] = $this->context();
        $this->guide($tenant, $supervisor->id, 1, 'Buin');
        $record = DB::table('Ope_Choferes')->where('id', $driverId)->firstOrFail();
        $key = app(OperationDriverJourneyService::class)->assigned($tenant, $record, '2026-10-09')->first()['key'];
        $this->actingAs($driver)->post(route('operations.driver.claim'), ['date' => '2026-10-09', 'key' => $key]);
        $journey = DB::table('Ope_Recorridos')->firstOrFail();
        $stop = DB::table('Ope_RecorridoParadas')->firstOrFail();
        $localUrl = "http://192.168.1.65:8002/operaciones/mi-ruta/{$journey->id}";

        $this->get($localUrl)->assertOk()->assertSee('Prueba local sin HTTPS');
        $this->post("{$localUrl}/iniciar", [
            'plate' => 'ABCD12', 'start_odometer' => 100,
            'latitude' => -33.4, 'longitude' => -70.7,
            'location_source' => 'manual', 'vehicle_no_observations' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('manual', DB::table('Ope_Recorridos')->where('id', $journey->id)->value('start_location_source'));
        $this->get($localUrl)->assertSee('Ubicación inicial ingresada manualmente');

        $this->post("{$localUrl}/paradas/{$stop->id}/trayecto")->assertSessionHasNoErrors();
        $this->post("{$localUrl}/paradas/{$stop->id}/llegada", [
            'latitude' => -33.5, 'longitude' => -70.6, 'location_source' => 'manual',
        ])->assertSessionHasNoErrors();
        $this->assertSame('manual', DB::table('Ope_RecorridoParadas')->where('id', $stop->id)->value('arrival_location_source'));
        $this->post("http://example.com/operaciones/mi-ruta/{$journey->id}/paradas/{$stop->id}/llegada", [
            'latitude' => -33.5, 'longitude' => -70.6, 'location_source' => 'manual',
        ])->assertSessionHasErrors('location_source');
    }

    public function test_post_returns_default_to_me_or_assign_selected_driver_at_each_stop(): void
    {
        [$tenant, $supervisor, $driver, $driverId] = $this->context();
        $this->guide($tenant, $supervisor->id, 1, 'Concepción', 'post');
        $this->guide($tenant, $supervisor->id, 2, 'Los Ángeles', 'post');
        $recipient = User::factory()->create(['profile_name' => 'Chofer']);
        $recipient->tenants()->attach($tenant, ['role_code' => 'driver', 'is_active' => true]);
        $recipientId = DB::table('Ope_Choferes')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $recipient->id,
            'rut' => '77.777.777-7', 'name' => 'Chofer receptor', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $record = DB::table('Ope_Choferes')->where('id', $driverId)->firstOrFail();
        $key = app(OperationDriverJourneyService::class)->assigned($tenant, $record, '2026-10-09')->first()['key'];
        $this->actingAs($driver)->post(route('operations.driver.claim'), ['date' => '2026-10-09', 'key' => $key]);
        $journey = DB::table('Ope_Recorridos')->firstOrFail();
        [$first, $second] = DB::table('Ope_RecorridoParadas')->orderBy('sequence')->get()->all();
        $this->get(route('operations.driver.show', $journey->id))->assertOk()->assertSee('YO · Chofer Prueba')->assertSee('Chofer receptor');
        $this->post(route('operations.driver.start', $journey->id), [
            'plate' => 'ABCD12', 'start_odometer' => 100,
            'latitude' => -33.4, 'longitude' => -70.7, 'vehicle_no_observations' => 1,
        ])->assertSessionHasNoErrors();
        $this->post(route('operations.driver.depart', [$journey->id, $first->id]))->assertSessionHasNoErrors();
        $this->post(route('operations.driver.arrive', [$journey->id, $first->id]), ['latitude' => -36.8, 'longitude' => -73.0])->assertSessionHasNoErrors();
        $this->post(route('operations.driver.complete', [$journey->id, $first->id]), [
            'return_count' => 2, 'return_receiver' => (string) $recipientId,
            'signed_guide_photo' => UploadedFile::fake()->image('signed-first.jpg'),
            'return_photo' => UploadedFile::fake()->image('returns-first.jpg'),
        ])->assertSessionHasNoErrors();
        $transfer = DB::table('Ope_RecorridoTraspasos')->firstOrFail();
        $this->assertSame($first->id, $transfer->stop_id);
        $this->assertSame($recipientId, $transfer->to_driver_id);
        $this->assertSame('pending', $transfer->status);
        $this->assertSame(0, app(OperationDriverJourneyService::class)->returnBalance($journey->id)['available']);
        $this->get(route('operations.driver.show', $journey->id))->assertSee('Asignadas al recoger: Chofer receptor')->assertSee('Esperando confirmación del chofer');

        $this->post(route('operations.driver.depart', [$journey->id, $second->id]))->assertSessionHasNoErrors();
        $this->post(route('operations.driver.arrive', [$journey->id, $second->id]), ['latitude' => -37.4, 'longitude' => -72.3])->assertSessionHasNoErrors();
        $this->post(route('operations.driver.complete', [$journey->id, $second->id]), [
            'return_count' => 1, 'return_receiver' => 'self',
            'signed_guide_photo' => UploadedFile::fake()->image('signed-second.jpg'),
            'return_photo' => UploadedFile::fake()->image('returns-second.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('Ope_RecorridoTraspasos')->count());
        $this->assertSame(1, app(OperationDriverJourneyService::class)->returnBalance($journey->id)['available']);
        $this->get(route('operations.driver.show', $journey->id))->assertSee('Asignadas al recoger: YO · Chofer Prueba');
        $this->actingAs($recipient)->get(route('operations.driver.index'))->assertSee('Concepción');
    }
}
