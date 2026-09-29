<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Provider;
use App\Models\RutaCv;
use App\Models\RutaCvFrequency;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RutaCvTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preserves_daily_and_fixed_payments_and_flags_unmatched_billers(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'legal_name' => 'SANREY SPA',
            'operational_name' => 'Claudia Reyes (Courier Stgo)', 'tax_id' => '77656334-K',
        ]);
        $path = $this->workbook();

        $this->post(route('provider-payments.courier-movements.rutas-cv.import'), [
            'file' => new UploadedFile($path, 'Base_RutaCV.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202608']))
            ->assertSessionHas('status', '2 rutas cargadas para 202608. 1 facturadores pendientes de asociar.');

        $daily = RutaCv::query()->where('facturador', 'SANREY')->firstOrFail();
        $this->assertSame($provider->id, $daily->provider_id);
        $this->assertSame('77656334-K', $daily->rut_proveedor);
        $this->assertSame([3, 4, 5], $daily->dias);
        $this->assertSame(105000, $daily->total_mensual);
        $fixed = RutaCv::query()->where('tipo_cobro', 'fijo')->firstOrFail();
        $this->assertNull($fixed->provider_id);
        $this->assertSame(460000, $fixed->total_mensual);
        $this->get(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202608']))
            ->assertOk()->assertSee('Facturador sin maestro')->assertSee('Guardar todos los cambios')
            ->assertSee('Sábado 01/08/2026')->assertSee('Proveedor / nombre de pila')->assertSee('RUT proveedor')
            ->assertSee('Razón social')->assertSee('Monto fijo mensual ($)')
            ->assertSee('Claudia Reyes (Courier Stgo)')->assertSee('cv-calendar-grid', false)
            ->assertDontSee('Facturador original');
        $this->assertSame(2, RutaCvFrequency::query()->where('tenant_id', $tenant->id)->count());

        $this->post(route('provider-payments.courier-movements.rutas-cv.import'), [
            'file' => new UploadedFile($path, 'Base_RutaCV.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertSessionHasErrors('file');
        $this->assertSame(2, RutaCv::query()->count());
        unlink($path);
    }

    public function test_month_generation_is_editable_and_does_not_create_payment_movements(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        RutaCvFrequency::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Ruta Lu a Vi',
            'name_key' => 'ruta-lu-a-vi', 'weekdays' => [1, 2, 3, 4, 5],
        ]);
        RutaCvFrequency::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Ruta Lu/Mi/Vi',
            'name_key' => 'ruta-lu-mi-vi', 'weekdays' => [1, 3, 5],
        ]);
        $daily = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => null,
            'frecuencia' => 'Ruta Lu a Vi', 'valor' => 40000,
        ]);
        $fixed = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'route_key' => 'fixed-route',
            'frecuencia' => 'Ruta Lu/Mi/Vi', 'tipo_cobro' => 'fijo', 'valor' => 460000,
            'monto_fijo' => 460000, 'total_mensual' => 460000,
        ]);

        $this->post(route('provider-payments.courier-movements.rutas-cv.generate'), [
            'periodo_month' => '2026-09',
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']));
        $nextDaily = RutaCv::query()->where('periodo', '202609')->where('route_key', $daily->route_key)->firstOrFail();
        $nextFixed = RutaCv::query()->where('periodo', '202609')->where('route_key', $fixed->route_key)->firstOrFail();
        $this->assertContains(1, $nextDaily->dias);
        $this->assertNotContains(5, $nextDaily->dias);
        $this->assertSame(count($nextDaily->dias) * 40000, $nextDaily->total_mensual);
        $this->assertSame(460000, $nextFixed->total_mensual);
        $this->get(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertOk()->assertSee('Martes 01/09/2026')->assertSee('Extra 31');

        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202609',
            'rows' => [
                $this->rowInput($nextDaily, ['dias' => [1], 'inasistencia' => 2]),
                $this->rowInput($nextFixed, ['monto_fijo' => 480000]),
            ],
        ])->assertSessionHasErrors('rows');
        $this->assertSame(460000, $nextFixed->fresh()->total_mensual);

        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202609',
            'rows' => [
                $this->rowInput($nextDaily, ['dias' => [1, 1]]),
                $this->rowInput($nextFixed, ['dias' => [1, 4]]),
            ],
        ])->assertSessionHasErrors('rows');

        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202609',
            'rows' => [
                $this->rowInput($nextDaily, ['provider_id' => $provider->id, 'dias' => [1, 5, 31], 'inasistencia' => 1]),
                $this->rowInput($nextFixed, ['dias' => [1, 4], 'monto_fijo' => 480000]),
            ],
        ])->assertSessionHas('status', '2 rutas guardadas.');
        $this->assertSame(80000, $nextDaily->fresh()->total_mensual);
        $this->assertSame(480000, $nextFixed->fresh()->total_mensual);
        $this->assertSame($provider->tax_id, $nextDaily->fresh()->rut_proveedor);
        $this->assertSame(0, CourierPaymentMovement::query()->count());

        $this->post(route('provider-payments.courier-movements.rutas-cv.generate'), [
            'periodo_month' => '2026-09',
        ])->assertSessionHasErrors('periodo');
        $this->assertSame(2, RutaCv::query()->where('periodo', '202609')->count());
    }

    public function test_custom_frequency_proposes_its_weekdays_for_a_future_month(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608',
            'frecuencia' => 'Ruta Sa y Do', 'valor' => 10000,
        ]);

        $this->post(route('provider-payments.courier-movements.rutas-cv.frequencies.store'), [
            'name' => 'Ruta Sa y Do', 'weekdays' => [6, 7], 'periodo' => '202608',
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202608']))
            ->assertSessionHas('status');
        $this->assertSame([6, 7], RutaCvFrequency::query()->where('name_key', 'ruta-sa-y-do')->firstOrFail()->weekdays);

        $this->post(route('provider-payments.courier-movements.rutas-cv.generate'), [
            'periodo_month' => '2026-09',
        ])->assertSessionHas('status');
        $days = RutaCv::query()->where('periodo', '202609')->firstOrFail()->dias;
        $this->assertContains(5, $days);
        $this->assertContains(6, $days);
        $this->assertNotContains(1, $days);
        $this->assertSame(count($days) * 10000, RutaCv::query()->where('periodo', '202609')->firstOrFail()->total_mensual);
    }

    public function test_known_slash_frequencies_generate_days_even_when_legacy_catalog_is_empty(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach (['Ruta Lu/Mi/Vi', 'Ruta Ma/Ju/Vi'] as $frequency) {
            RutaCvFrequency::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => $frequency,
                'name_key' => Str::slug($frequency),
                'weekdays' => [],
            ]);
            RutaCv::factory()->create([
                'tenant_id' => $tenant->id,
                'periodo' => '202608',
                'frecuencia' => $frequency,
                'valor' => 35000,
            ]);
        }

        $this->post(route('provider-payments.courier-movements.rutas-cv.generate'), [
            'periodo_month' => '2026-09',
        ])->assertSessionHas('status');

        $mondayWednesdayFriday = RutaCv::query()->where('periodo', '202609')->where('frecuencia', 'Ruta Lu/Mi/Vi')->firstOrFail();
        $tuesdayThursdayFriday = RutaCv::query()->where('periodo', '202609')->where('frecuencia', 'Ruta Ma/Ju/Vi')->firstOrFail();
        $this->assertSame([2, 4, 7, 9, 11, 14, 16, 18, 21, 23, 25, 28, 30], $mondayWednesdayFriday->dias);
        $this->assertSame(455000, $mondayWednesdayFriday->total_mensual);
        $this->assertSame([1, 3, 4, 8, 10, 11, 15, 17, 18, 22, 24, 25, 29], $tuesdayThursdayFriday->dias);
        $this->assertSame(455000, $tuesdayThursdayFriday->total_mensual);
    }

    public function test_manually_added_route_marks_days_for_its_period_and_can_be_removed_while_open(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        RutaCvFrequency::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Ruta Lu/Mi/Vi',
            'name_key' => Str::slug('Ruta Lu/Mi/Vi'), 'weekdays' => [1, 3, 5],
        ]);
        $other = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609',
            'frecuencia' => 'Ruta Lu/Mi/Vi', 'dias' => [1], 'valor' => 10000, 'total_mensual' => 10000,
        ]);

        $this->post(route('provider-payments.courier-movements.rutas-cv.store'), [
            'periodo' => '202609', 'zona' => 'RM', 'frecuencia' => 'Ruta Lu/Mi/Vi',
            'facturador' => 'Nuevo', 'usuario' => 'Usuario nuevo', 'detalle_ruta' => 'Nueva ruta',
            'comuna' => 'Santiago', 'valor' => 35000, 'tipo_cobro' => 'diario',
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertSessionHas('status');

        $added = RutaCv::query()->where('periodo', '202609')->where('detalle_ruta', 'Nueva ruta')->firstOrFail();
        $this->assertSame([2, 4, 7, 9, 11, 14, 16, 18, 21, 23, 25, 28, 30], $added->dias);
        $this->assertSame(455000, $added->total_mensual);
        $this->get(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertOk()->assertSee('Eliminar ruta')->assertSee('Cambiar proveedor')
            ->assertSee('Créalo y vuelve a este período');

        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202609', 'delete_route_id' => $added->id,
            'rows' => [$this->rowInput($other), ['id' => $added->id]],
        ])
            ->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertSessionHas('status');
        $this->assertNull($added->fresh());
        $this->assertNotNull($other->fresh());
        $this->get(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertOk()->assertSee('10.000')->assertDontSee('Nueva ruta');
    }

    public function test_route_from_previous_base_can_be_excluded_before_generating_new_month(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        RutaCvFrequency::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Ruta Lu a Vi',
            'name_key' => 'ruta-lu-a-vi', 'weekdays' => [1, 2, 3, 4, 5],
        ]);
        $keep = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608',
            'frecuencia' => 'Ruta Lu a Vi', 'detalle_ruta' => 'Ruta que continúa',
        ]);
        $exclude = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608',
            'frecuencia' => 'Ruta Lu a Vi', 'detalle_ruta' => 'Ruta que termina',
        ]);

        $this->get(route('provider-payments.courier-movements.rutas-cv.source-routes', ['periodo_month' => '2026-09']))
            ->assertOk()->assertJsonPath('source_period', '202608')->assertJsonCount(2, 'routes');
        $this->post(route('provider-payments.courier-movements.rutas-cv.generate'), [
            'periodo_month' => '2026-09', 'exclude_route_ids' => [$exclude->id],
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertSessionHas('status');

        $this->assertSame(2, RutaCv::query()->where('periodo', '202608')->count());
        $this->assertSame(1, RutaCv::query()->where('periodo', '202609')->count());
        $copy = RutaCv::query()->where('periodo', '202609')->firstOrFail();
        $this->assertSame($keep->route_key, $copy->route_key);
        $this->assertNotEmpty($copy->dias);
    }

    public function test_deleting_route_saves_other_unsaved_days_in_the_same_operation(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        RutaCvFrequency::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Ruta Lu a Vi',
            'name_key' => 'ruta-lu-a-vi', 'weekdays' => [1, 2, 3, 4, 5],
        ]);
        $keep = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609',
            'frecuencia' => 'Ruta Lu a Vi', 'dias' => [1],
            'valor' => 35000, 'total_mensual' => 35000,
        ]);
        $remove = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609',
            'frecuencia' => 'Ruta Lu a Vi', 'dias' => [1],
        ]);

        $this->get(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertOk()->assertSee('name="delete_route_id" value="'.$remove->id.'"', false)
            ->assertDontSee('form="cv-delete-', false);
        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202609', 'delete_route_id' => $remove->id,
            'rows' => [
                $this->rowInput($keep, ['dias' => [2, 4, 7]]),
                ['id' => $remove->id, 'zona' => ''],
            ],
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertSessionHas('status', '1 ruta guardada y una ruta eliminada.');

        $this->assertSame([2, 4, 7], $keep->fresh()->dias);
        $this->assertSame(105000, $keep->fresh()->total_mensual);
        $this->assertNull($remove->fresh());

        $secondRemove = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609', 'frecuencia' => 'Ruta Lu a Vi',
        ]);
        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202609', 'delete_route_id' => $secondRemove->id,
            'rows' => [
                $this->rowInput($keep, ['dias' => [1], 'inasistencia' => 2]),
                ['id' => $secondRemove->id],
            ],
        ])->assertSessionHasErrors('rows');
        $this->assertSame([2, 4, 7], $keep->fresh()->dias);
        $this->assertNotNull($secondRemove->fresh());
    }

    public function test_closed_route_cannot_be_deleted_and_new_provider_can_return_to_same_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $closed = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609', 'closed_at' => now(),
        ]);
        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202609', 'delete_route_id' => $closed->id,
            'rows' => [['id' => $closed->id]],
        ])
            ->assertSessionHasErrors('periodo');
        $this->assertNotNull($closed->fresh());

        $this->get(route('provider-payments.maintainers.proveedores', ['return_period' => '202609']))
            ->assertOk()->assertSee('Volver a Rutas CV 202609')
            ->assertSee('name="return_period" value="202609"', false);
        $this->post(route('provider-payments.maintainers.proveedores.store'), [
            'return_period' => '202609', 'tax_id' => '11942383-K',
            'legal_name' => 'Nuevo proveedor de ruta', 'operational_name' => 'Proveedor nuevo',
            'operator_type' => 'Courier',
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202609']))
            ->assertSessionHas('status');
        $this->assertDatabaseHas('providers', [
            'tenant_id' => $tenant->id, 'tax_id' => '11942383-K', 'operational_name' => 'Proveedor nuevo',
        ]);
    }

    public function test_frequency_repair_only_fills_open_generated_routes_without_selected_days(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $frequency = RutaCvFrequency::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Ruta Lu/Mi/Vi',
            'name_key' => Str::slug('Ruta Lu/Mi/Vi'),
            'weekdays' => [],
        ]);
        $attributes = ['tenant_id' => $tenant->id, 'periodo' => '202609',
            'frecuencia' => 'Ruta Lu/Mi/Vi', 'dias' => [], 'valor' => 35000,
            'total_mensual' => 0, 'origen' => 'generado'];
        $open = RutaCv::factory()->create($attributes);
        $manual = RutaCv::factory()->create(array_merge($attributes, ['origen' => 'manual']));
        $closed = RutaCv::factory()->create(array_merge($attributes, ['closed_at' => now()]));
        $reviewed = RutaCv::factory()->create(array_merge($attributes, ['dias' => [2], 'total_mensual' => 35000]));

        (require database_path('migrations/2026_09_28_205059_repair_ruta_cv_slash_frequencies.php'))->up();

        $this->assertSame([1, 3, 5], $frequency->fresh()->weekdays);
        $this->assertSame([2, 4, 7, 9, 11, 14, 16, 18, 21, 23, 25, 28, 30], $open->fresh()->dias);
        $this->assertSame(455000, $open->fresh()->total_mensual);
        $this->assertSame([], $manual->fresh()->dias);
        $this->assertSame([], $closed->fresh()->dias);
        $this->assertSame([2], $reviewed->fresh()->dias);
    }

    public function test_closing_creates_linked_payments_locks_routes_and_master_key_reopens_them(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'tax_id' => '89807200-2'],
            ['tax_id_number' => '89807200', 'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cruz Verde',
                'commercial_name' => 'Cruz Verde', 'legal_name' => 'Farmacias Cruz Verde Spa'],
        );
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1',
            'legal_name' => 'Proveedor CV SpA', 'operational_name' => 'Proveedor CV']);
        RutaCvFrequency::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ruta Lu a Vi', 'name_key' => 'ruta-lu-a-vi']);
        $daily = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'dias' => [3, 4, 5],
            'inasistencia' => 1, 'valor' => 35000, 'total_mensual' => 70000,
            'usuario' => 'Usuario Ruta', 'detalle_ruta' => 'Ruta Santiago', 'comuna' => 'Santiago',
        ]);
        $fixed = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'dias' => [1, 2],
            'route_key' => 'ruta-fija', 'tipo_cobro' => 'fijo', 'valor' => 0,
            'monto_fijo' => 100000, 'total_mensual' => 100000,
        ]);
        CourierMovement::query()->create(['tenant_id' => $tenant->id, 'tracking_number' => 'RCV-20260801-0007']);

        $this->post(route('provider-payments.courier-movements.rutas-cv.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->assertNotNull($daily->fresh()->closed_at);
        $this->assertNotNull($fixed->fresh()->closed_at);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'ruta_cv_id' => $daily->id, 'periodo' => '202608', 'nombre_proceso' => 'Ruta CV',
            'tipo_pago' => 'Ruta CV', 'seguimiento_paquete' => 'RCV-20260801-0008',
            'zona' => 'RM', 'comuna_destino' => 'Santiago', 'rut_cliente' => $client->tax_id,
            'razon_social_cliente' => $client->legal_name, 'rut_proveedor' => $provider->tax_id,
            'peso_final' => 2, 'estado_envio' => 'Entregado', 'nombre_repartidor' => 'Usuario Ruta',
            'usuario_entrega' => 'Usuario Ruta', 'empresa_mandante' => '4N', 'valor' => 70000,
            'condicion_pago' => 'SI',
        ]);
        $payment = CourierPaymentMovement::query()->where('ruta_cv_id', $daily->id)->firstOrFail();
        $this->assertSame('2026-08-01', $payment->fecha->toDateString());
        $this->assertSame('Ruta Santiago', $payment->direccion);
        $this->assertSame('RCV-20260801-0009', CourierPaymentMovement::query()->where('ruta_cv_id', $fixed->id)->value('seguimiento_paquete'));
        $this->assertSame(100000, CourierPaymentMovement::query()->where('ruta_cv_id', $fixed->id)->value('valor'));
        $this->assertSame('Ruta CV', CourierMovement::query()->findOrFail($payment->courier_movement_id)->source_system);
        $this->get(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202608']))
            ->assertOk()->assertSee('Período cerrado')->assertDontSee('Agregar ruta adicional');
        $this->get(route('provider-payments.movements.index', ['period' => '202608', 'process' => 'Ruta CV']))
            ->assertOk()->assertSee('Proveedor CV')->assertDontSee('Sin proveedor asociado');

        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202608', 'rows' => [$this->rowInput($daily), $this->rowInput($fixed)],
        ])->assertSessionHasErrors('periodo');
        $this->post(route('provider-payments.courier-movements.rutas-cv.store'), [
            'periodo' => '202608', 'zona' => 'RM', 'frecuencia' => 'Ruta Lu a Vi', 'facturador' => 'Nuevo',
            'usuario' => 'Nuevo', 'detalle_ruta' => 'Nueva ruta', 'comuna' => 'Santiago', 'valor' => 100,
            'tipo_cobro' => 'diario',
        ])->assertSessionHasErrors('periodo');

        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202608'])->assertRedirect();
        $this->assertSame(70000, $payment->fresh()->valor);
        config()->set('provider-payments.process_deletion_key', 'test-master-key');
        $this->post(route('provider-payments.courier-movements.rutas-cv.reopen'), [
            'periodo' => '202608', 'password' => 'incorrecta',
        ])->assertSessionHasErrors('password');
        $this->assertNotNull($daily->fresh()->closed_at);

        $this->post(route('provider-payments.courier-movements.rutas-cv.reopen'), [
            'periodo' => '202608', 'password' => 'test-master-key',
        ])->assertSessionHas('status');
        $this->assertNull($daily->fresh()->closed_at);
        $this->assertSame(0, CourierPaymentMovement::query()->whereNotNull('ruta_cv_id')->count());
        $this->assertSame(0, CourierMovement::query()->where('source_system', 'Ruta CV')->count());
        $this->assertSame(1, CourierMovement::query()->where('tracking_number', 'RCV-20260801-0007')->count());

        $this->post(route('provider-payments.courier-movements.rutas-cv.update'), [
            'periodo' => '202608', 'rows' => [
                $this->rowInput($daily, ['valor' => 40000]), $this->rowInput($fixed),
            ],
        ])->assertSessionHas('status');
        $this->post(route('provider-payments.courier-movements.rutas-cv.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->assertSame(80000, CourierPaymentMovement::query()->where('ruta_cv_id', $daily->id)->value('valor'));
        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Ruta CV', 'password' => 'test-master-key',
        ])->assertRedirect(route('provider-payments.courier-movements.rutas-cv', ['periodo' => '202608']));
        $this->assertNull($daily->fresh()->closed_at);
        $this->assertSame(0, CourierPaymentMovement::query()->whereNotNull('ruta_cv_id')->count());
    }

    public function test_closing_rolls_back_everything_if_a_route_has_no_provider(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Client::query()->firstOrCreate(['tenant_id' => $tenant->id, 'tax_id' => '89807200-2'], [
            'tax_id_number' => '89807200', 'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cruz Verde',
            'commercial_name' => 'Cruz Verde', 'legal_name' => 'Farmacias Cruz Verde Spa',
        ]);
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        RutaCv::factory()->create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id]);
        RutaCv::factory()->create(['tenant_id' => $tenant->id, 'provider_id' => null]);

        $this->post(route('provider-payments.courier-movements.rutas-cv.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->assertSame(0, CourierPaymentMovement::query()->count());
        $this->assertSame(0, CourierMovement::query()->count());
        $this->assertSame(0, RutaCv::query()->whereNotNull('closed_at')->count());
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function rowInput(RutaCv $route, array $changes = []): array
    {
        $route = $route->fresh();

        return array_replace([
            'id' => $route->id, 'provider_id' => $route->provider_id,
            'zona' => $route->zona, 'frecuencia' => $route->frecuencia,
            'facturador' => $route->facturador, 'usuario' => $route->usuario,
            'detalle_ruta' => $route->detalle_ruta, 'comuna' => $route->comuna,
            'producto' => $route->producto, 'agente' => $route->agente,
            'valor' => $route->valor, 'tipo_cobro' => $route->tipo_cobro,
            'monto_fijo' => $route->monto_fijo, 'inasistencia' => $route->inasistencia,
            'dias' => $route->dias, 'observacion' => null,
        ], $changes);
    }

    private function workbook(): string
    {
        $sheet = new Spreadsheet;
        $header = ['Periodo', 'Proceso', 'Zona', 'Servicio', 'Frecuencia', 'Facturador', 'Usuario', 'Detalle-Ruta', 'Comuna', 'Producto', 'Valor', 'Agente', ...range(1, 31), 'Mes', 'Inasistencia', 'TotalMensual', 'Observacion'];
        $dailyDays = array_fill(0, 31, null);
        $dailyDays[2] = $dailyDays[3] = $dailyDays[4] = 'x';
        $fixedDays = array_fill(0, 31, null);
        $fixedDays[2] = $fixedDays[4] = 'x';
        $sheet->getActiveSheet()->fromArray([
            $header,
            [202608, 'Ruta CV', 'RM', 'Ruta CV', 'Ruta Lu a Vi', 'SANREY', 'Claudia', 'Centro', 'Santiago', 'Ruta de Cruz Verde', 35000, '4N RM', ...$dailyDays, 3, 0, '=+(AR2-AS2)*K2', 0],
            [202608, 'Ruta CV', 'RM', 'Ruta CV', 'Ruta Lu/Mi/Vi', 'Facturador sin maestro', 'Repartidor', 'Colina', 'Colina', 'Ruta de Cruz Verde', 460000, '4N RM', ...$fixedDays, 2, 0, 460000, 0],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'rutas-cv-').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        return $path;
    }
}
