<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FleetWeeklyKilometersTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_save_calculates_remaining_and_keeps_audited_readings(): void
    {
        [$tenant, $user] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'KMTEST1');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 19000)]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(19000, $vehicle->fresh()->odometer_km);
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $vehicle->id, 'actor_user_id' => $user->id, 'week_start' => '2026-10-05', 'accepted' => true]);
        $response = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-05']));
        $response->assertOk()->assertSee('Programar mantención')->assertSee('Kilometrajes y alertas');
        $row = $response->viewData('rows')->first();
        $this->assertSame(20000, $row['due']);
        $this->assertSame(1000, $row['remaining']);
        $this->get(route('fleet.vehicles.show', $vehicle))->assertOk()->assertSee('Historial de kilometrajes')->assertSee('19.000 km');
    }

    public function test_board_reading_updates_the_same_vehicle_maintenance_alert(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'BOARDKM');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'return_to' => 'fleet', 'readings' => [$this->reading($vehicle, 19000)]])->assertRedirect(route('fleet.page.operations-fleet'));
        $response = $this->get(route('fleet.page.operations-fleet'));
        $row = $response->viewData('rows')->firstWhere('vehicle.id', $vehicle->id);
        $this->assertSame('warning', $row['display_state']);
        $this->assertSame(1000, $row['km']['remaining']);
        $response->assertSee('data-open-km="'.$vehicle->id.'"', false)->assertSee('Ingresar kilometrajes semanales');
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $vehicle->id, 'odometer_km' => 19000]);
    }

    public function test_bulk_capture_shows_all_current_plates_and_previews_pasted_plate_km_before_saving(): void
    {
        [$tenant] = $this->workspace();
        $healthy = $this->vehicle($tenant, 'BULK01');
        $workshop = $this->vehicle($tenant, 'BULK02');
        $sold = $this->vehicle($tenant, 'BULK03');
        $workshop->update(['operational_status' => 'En mantención']);
        $sold->update(['operational_status' => 'Vendido']);
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($healthy, 15000)]])->assertSessionHasNoErrors();
        $captureUrl = route('fleet.kilometers.index', ['semana' => '2026-10-05', 'captura' => 'all']);
        $this->get($captureUrl)->assertOk()->assertSee('BULK01')->assertSee('BULK02')->assertDontSee('BULK03')->assertSee('En taller')->assertSee('name="readings['.$healthy->id.'][odometer_km]"', false);

        $this->post(route('fleet.kilometers.preview'), ['semana' => '2026-10-05', 'return_to' => 'km_capture', 'pegado' => 'Patente	KM
BULK01	17.000
BULK02	15.500
BULK03	20000'])->assertSessionHasNoErrors()->assertRedirect($captureUrl);

        $this->assertDatabaseCount('VEH_weekly_readings', 1);
        $this->get($captureUrl)->assertSee('value="17000"', false)->assertSee('value="15500"', false)->assertDontSee('BULK03');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'return_to' => 'km_capture', 'readings' => [$this->reading($healthy, 17000), $this->reading($workshop, 15500)]])->assertSessionHasNoErrors()->assertRedirect($captureUrl);
        $this->assertDatabaseCount('VEH_weekly_readings', 3);
        $this->assertSame(17000, $healthy->fresh()->odometer_km);
    }

    public function test_workshop_keeps_last_valid_odometer_before_entry_despite_zero_or_later_readings(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'FREEZEKM');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 16000)]])->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-10-09 11:00:00', 'America/Santiago'));
        $vehicle->refresh();
        $this->patch(route('fleet.vehicles.status', $vehicle), ['target_state' => 'unavailable', 'reason_code' => 'maintenance', 'reason' => 'Ingreso al taller', 'expected_status' => 'Activa', 'expected_revision' => $vehicle->updated_at->toISOString()])->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'America/Santiago'));
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 0)]])->assertSessionHasNoErrors();
        $row = $this->get(route('fleet.kilometers.index', ['captura' => 'all']))->viewData('rows')->firstWhere('vehicle.id', $vehicle->id);
        $this->assertSame(16000, $row['current']);
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 17000)]])->assertSessionHasNoErrors();
        $row = $this->get(route('fleet.kilometers.index', ['captura' => 'all']))->viewData('rows')->firstWhere('vehicle.id', $vehicle->id);
        $this->assertSame(16000, $row['current']);
        $this->assertSame(4000, $row['remaining']);
        $this->assertTrue($row['in_workshop']);
        $this->assertSame(17000, $vehicle->fresh()->odometer_km);
        $this->assertDatabaseCount('VEH_weekly_readings', 3);
    }

    public function test_sold_and_stolen_are_excluded_without_losing_history(): void
    {
        [$tenant] = $this->workspace();
        $sold = $this->vehicle($tenant, 'SOLDKM');
        $stolen = $this->vehicle($tenant, 'STOLKM');
        $active = $this->vehicle($tenant, 'ACTIVEKM');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($sold, 15000)]])->assertSessionHasNoErrors();
        $sold->update(['operational_status' => 'Vendido', 'is_active' => false]);
        $stolen->update(['operational_status' => 'Robado', 'is_active' => false]);
        $response = $this->get(route('fleet.kilometers.index'));
        $response->assertOk()->assertDontSee('SOLDKM')->assertDontSee('STOLKM')->assertSee('ACTIVEKM');
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $sold->id]);
        $this->get(route('fleet.vehicles.show', $sold))->assertOk()->assertSee('15.000 km');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($sold, 16000)]])->assertForbidden();
    }

    public function test_zero_or_inconsistent_kilometers_do_not_reduce_vehicle(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'BADKM');
        $vehicle->update(['odometer_km' => 19000]);
        foreach ([0, 9000] as $km) {
            $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, $km)]])->assertSessionHasNoErrors();
            $this->assertSame(19000, $vehicle->fresh()->odometer_km);
        }
        $this->assertSame(2, DB::table('VEH_weekly_readings')->where('accepted', false)->count());
        $response = $this->get(route('fleet.kilometers.index'));
        $response->assertOk()->assertSee('Verificar kilometraje');
        $this->assertNull($response->viewData('rows')->first()['remaining']);
    }

    public function test_workshop_is_red_even_when_remaining_kilometers_are_positive(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'SHOPKM');
        VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Motor', 'execution_type' => 'internal', 'status' => 'in_progress']);
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 11000)]])->assertSessionHasNoErrors();
        $response = $this->get(route('fleet.kilometers.index'));
        $response->assertOk()->assertSee('No operativo: En mantención');
        $this->assertSame('red', $response->viewData('rows')->first()['level']);
    }

    public function test_preview_accepts_excel_paste_and_skips_archived_without_saving(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'PASTEKM');
        $sold = $this->vehicle($tenant, 'SOLDPASTE');
        $sold->update(['operational_status' => 'Vendido']);
        $text = "Patente\tKM Ultimo cambio\tKM Actual\tKm Recomenddo\tobs\nPASTEKM\t10.000\t19.000\t10.000\tRevisar\nSOLDPASTE\t10.000\t19.000\t10.000\t";
        $response = $this->post(route('fleet.kilometers.preview'), ['semana' => '2026-10-05', 'pegado' => $text]);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $response->assertSessionHas('_old_input.readings.'.$vehicle->id.'.odometer_km', 19000);
        $this->assertDatabaseCount('VEH_weekly_readings', 0);
        $this->assertNull($vehicle->fresh()->odometer_km);
    }

    public function test_read_only_and_foreign_tenant_cannot_save(): void
    {
        [$tenant] = $this->workspace('commercial');
        $vehicle = $this->vehicle($tenant, 'READKM');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 15000)]])->assertForbidden();
        [$tenant] = $this->workspace();
        $foreign = $this->vehicle(Tenant::where('code', 'PMCB')->firstOrFail(), 'FOREIGNKM');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($foreign, 15000)]])->assertForbidden();
        $this->assertDatabaseCount('VEH_weekly_readings', 0);
    }

    public function test_calendar_and_budget_use_existing_records_and_skip_archived_vehicles(): void
    {
        [$tenant] = $this->workspace();
        $active = $this->vehicle($tenant, 'CALKM');
        $sold = $this->vehicle($tenant, 'CALOLD');
        $sold->update(['operational_status' => 'Vendido']);
        foreach ([$active, $sold] as $vehicle) {
            VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Mantención aceite', 'execution_type' => 'internal', 'status' => 'scheduled', 'scheduled_at' => '2026-10-08 10:00:00', 'estimated_cost' => 50000]);
        }
        $response = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-05']));
        $response->assertOk()->assertSee('$50.000')->assertDontSee('CALOLD');
        $this->assertCount(1, $response->viewData('calendar'));
    }

    public function test_two_weekly_readings_allow_forecast_without_inventing_dates_from_one(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'FORECAST');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-09-28', 'readings' => [$this->reading($vehicle, 15000)]])->assertSessionHasNoErrors();
        $this->assertNull($this->get(route('fleet.kilometers.index', ['semana' => '2026-09-28']))->viewData('rows')->first()['forecast']);
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 16000)]])->assertSessionHasNoErrors();
        $row = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-05']))->viewData('rows')->first();
        $this->assertSame('2026-11-02', $row['forecast']->toDateString());
    }

    public function test_reaching_due_kilometers_is_red_and_future_week_is_rejected(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'DUEKM');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-12', 'readings' => [$this->reading($vehicle, 20000)]])->assertSessionHasErrors('semana');
        $this->assertDatabaseCount('VEH_weekly_readings', 0);
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 20000)]])->assertSessionHasNoErrors();
        $response = $this->get(route('fleet.kilometers.index'));
        $response->assertOk()->assertSee('Mantención vencida');
        $this->assertSame('red', $response->viewData('rows')->first()['level']);
    }

    public function test_xlsx_preview_reads_grouped_kilometers_without_saving(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'EXCELKM');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Patente', 'KM Ultimo cambio', 'KM Actual', 'Km Recomendado'], ['EXCELKM', 10000, 19000, 10000]]);
        $stream = fopen('php://temp', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();
        $file = UploadedFile::fake()->createWithContent('kilometros.xlsx', $content);
        $this->post(route('fleet.kilometers.preview'), ['semana' => '2026-10-05', 'archivo' => $file])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('_old_input.readings.'.$vehicle->id.'.odometer_km', 19000);
        $this->assertDatabaseCount('VEH_weekly_readings', 0);
    }

    public function test_duplicate_plate_preview_is_rejected_and_current_only_import_preserves_parameters(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'DUPKM');
        $this->post(route('fleet.kilometers.preview'), ['semana' => '2026-10-05', 'pegado' => "Patente\tKM Actual\nDUPKM\t19000\nDUPKM\t19001"])->assertSessionHasErrors('archivo');
        $this->post(route('fleet.kilometers.preview'), ['semana' => '2026-10-05', 'pegado' => "Patente\tKM Actual\nDUPKM\t19000"])->assertSessionHasNoErrors()->assertSessionHas('_old_input.readings.'.$vehicle->id.'.odometer_km', 19000);
        $this->assertArrayNotHasKey('interval_km', session('_old_input.readings')[$vehicle->id]);
    }

    public function test_floating_trend_compares_exact_mondays_and_uses_latest_correction(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'TRENDKM');
        foreach ([['2026-09-28', 15000], ['2026-10-05', 16000], ['2026-10-05', 16500]] as [$date, $km]) {
            $this->post(route('fleet.kilometers.store'), ['semana' => $date, 'readings' => [$this->reading($vehicle, $km)]])->assertSessionHasNoErrors();
        }
        $response = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-05']));
        $response->assertOk()->assertSee('Recorrió 1.500 km esta semana.')->assertSee('km-trend-chart', false)->assertDontSee('id="km-history"', false);
        $trend = $response->viewData('trends')->get($vehicle->id);
        $this->assertSame(1500, $trend['delta']);
        $this->assertCount(12, $trend['series']);
        $this->assertCount(2, $trend['series']->whereNotNull('km'));
        $this->assertDatabaseCount('VEH_weekly_readings', 3);
    }

    public function test_missing_previous_monday_is_not_compared_to_an_older_week(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'GAPKM');
        foreach ([['2026-09-21', 15000], ['2026-10-05', 16000]] as [$date, $km]) {
            $this->post(route('fleet.kilometers.store'), ['semana' => $date, 'readings' => [$this->reading($vehicle, $km)]])->assertSessionHasNoErrors();
        }
        $response = $this->get(route('fleet.kilometers.index'));
        $response->assertOk()->assertSee('Sin datos para comparar estos dos lunes.');
        $this->assertNull($response->viewData('trends')->get($vehicle->id)['delta']);
        $past = $this->get(route('fleet.kilometers.index', ['semana' => '2026-09-21']));
        $this->assertCount(1, $past->viewData('trends')->get($vehicle->id)['series']->whereNotNull('km'));
    }

    public function test_decreasing_reading_is_shown_as_review_not_distance_travelled(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'DOWNTREND');
        foreach ([['2026-09-28', 16000], ['2026-10-05', 15000]] as [$date, $km]) {
            $this->post(route('fleet.kilometers.store'), ['semana' => $date, 'readings' => [$this->reading($vehicle, $km)]])->assertSessionHasNoErrors();
        }
        $response = $this->get(route('fleet.kilometers.index'));
        $response->assertOk()->assertSee('Variación informada: -1.000 km')->assertSee('Kilometraje menor al anterior: revisar.')->assertDontSee('Recorrió 1.000 km esta semana.');
        $this->assertSame(-1000, $response->viewData('trends')->get($vehicle->id)['delta']);
        $this->assertSame(16000, $vehicle->fresh()->odometer_km);
    }

    public function test_saved_readings_move_to_maintenance_or_leave_capture_according_to_remaining_and_weekly_projection(): void
    {
        [$tenant] = $this->workspace();
        $near = $this->vehicle($tenant, 'NEAR1500');
        $projected = $this->vehicle($tenant, 'PROJECTKM');
        $healthy = $this->vehicle($tenant, 'HEALTHYKM');
        $invalid = $this->vehicle($tenant, 'REVIEWKM');
        $workshop = $this->vehicle($tenant, 'WORKSHOPKM');
        $workshop->update(['operational_status' => 'En mantención']);
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-09-28', 'readings' => [$this->reading($projected, 16800)]])->assertSessionHasNoErrors();
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [
            $this->reading($near, 18500), $this->reading($projected, 17700), $this->reading($healthy, 18499),
            $this->reading($invalid, 0), $this->reading($workshop, 15000),
        ]])->assertSessionHasNoErrors();
        $response = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-05']));
        $response->assertOk()->assertSee('Toca mantención')->assertSee('12/10/2026')->assertSee('En taller')->assertDontSee('Estimación próximo lunes')->assertDontSee('Semana estimada');
        $this->assertEqualsCanonicalizing([$near->id, $projected->id], $response->viewData('maintenanceRows')->pluck('vehicle.id')->all());
        $this->assertSame([$healthy->id], $response->viewData('completedRows')->pluck('vehicle.id')->all());
        $this->assertSame([$invalid->id], $response->viewData('captureRows')->pluck('vehicle.id')->all());
        $this->assertSame([$workshop->id], $response->viewData('workshopRows')->pluck('vehicle.id')->all());
        $response->assertDontSee('name="readings['.$healthy->id.'][odometer_km]"', false);
        $row = $response->viewData('maintenanceRows')->firstWhere('vehicle.id', $projected->id);
        $this->assertSame(900, $row['average_weekly_km']);
        $this->assertSame(1400, $row['projected_remaining']);
        $this->assertSame('yellow', $row['level']);
        $this->assertDatabaseCount('VEH_weekly_readings', 6);

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'America/Santiago'));
        $next = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-12']));
        $this->assertCount(4, $next->viewData('captureRows'));
        $this->assertSame([$workshop->id], $next->viewData('workshopRows')->pluck('vehicle.id')->all());
        $this->assertCount(0, $next->viewData('maintenanceRows'));
        $this->assertCount(0, $next->viewData('completedRows'));
    }

    public function test_preview_can_correct_a_completed_reading_and_next_week_calendar_includes_only_visible_scheduled_work(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'COMPLETEDKM');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [$this->reading($vehicle, 15000)]])->assertSessionHasNoErrors();
        $job = VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Aceite', 'execution_type' => 'internal', 'status' => 'scheduled', 'scheduled_at' => '2026-10-13 13:00:00', 'estimated_cost' => 50000]);
        $this->post(route('fleet.kilometers.preview'), ['semana' => '2026-10-05', 'pegado' => "Patente\tKM Actual\nCOMPLETEDKM\t19000"])->assertSessionHasNoErrors();
        $response = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-05']));
        $response->assertOk()->assertSee('name="readings['.$vehicle->id.'][odometer_km]"', false)->assertSee('value="19000"', false);
        $this->assertCount(1, $response->viewData('captureRows'));
        $this->assertCount(0, $response->viewData('completedRows'));
        $this->assertSame([$job->id], $response->viewData('nextCalendar')->modelKeys());
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $vehicle->id, 'odometer_km' => 15000]);
    }

    public function test_capture_prefills_only_workshop_and_charts_eight_exact_weekly_distances(): void
    {
        [$tenant, $user] = $this->workspace();
        $active = $this->vehicle($tenant, 'BARACTIVE');
        $workshop = $this->vehicle($tenant, 'BARTALLER');
        $workshop->update(['operational_status' => 'En mantención']);
        foreach ([['2026-09-07', 11000, true], ['2026-09-14', 12000, true], ['2026-09-21', 12000, true], ['2026-09-28', 14000, true], ['2026-10-05', 13500, false]] as [$date, $km, $accepted]) {
            DB::table('VEH_weekly_readings')->insert([
                'vehicle_id' => $active->id, 'tenant_id' => $tenant->id, 'actor_user_id' => $user->id,
                'week_start' => $date, 'odometer_km' => $km, 'accepted' => $accepted,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-09-28', 'readings' => [$this->reading($workshop, 16000)]])->assertSessionHasNoErrors();
        $response = $this->get(route('fleet.kilometers.index', ['captura' => 'all', 'semana' => '2026-10-05']));
        $response->assertOk()->assertSee('Recorrido · 8 semanas')->assertSee('aria-label="Ingresar KM BARTALLER" value="16000"', false)
            ->assertSee('aria-label="Ingresar KM BARACTIVE" value=""', false);
        $bars = $response->viewData('weeklyBars')->get($active->id);
        $this->assertCount(8, $bars);
        $this->assertSame([1000, 0, 2000, null], $bars->take(-4)->pluck('km')->all());
        $this->assertSame('KM menor que la lectura anterior; revisar', $bars->last()['status']);
        $this->assertSame(['2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'], $bars->take(-4)->pluck('week')->all());
        $this->assertSame(2000, $response->viewData('barMaximums')->get($active->id));
        $this->assertSame(array_fill(0, 8, null), $response->viewData('weeklyBars')->get($workshop->id)->pluck('km')->all());
        $this->assertDatabaseCount('VEH_weekly_readings', 6);
    }

    public function test_chart_uses_reported_distance_despite_maintenance_observation_and_keeps_zero_gaps(): void
    {
        [$tenant, $user] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'OBSERVATION');
        $small = $this->vehicle($tenant, 'SMALLDIST');
        foreach ([['2026-08-31', 1000, true], ['2026-09-07', 2000, false], ['2026-09-14', 0, false], ['2026-09-21', 4000, true], ['2026-09-28', 4500, true], ['2026-10-05', 5200, true]] as [$date, $km, $accepted]) {
            DB::table('VEH_weekly_readings')->insert(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'actor_user_id' => $user->id, 'week_start' => $date, 'odometer_km' => $km, 'accepted' => $accepted, 'issues' => $accepted ? '[]' : json_encode(['KM actual menor a la última mantención']), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['2026-09-28', 10000], ['2026-10-05', 10100]] as [$date, $km]) {
            DB::table('VEH_weekly_readings')->insert(['vehicle_id' => $small->id, 'tenant_id' => $tenant->id, 'actor_user_id' => $user->id, 'week_start' => $date, 'odometer_km' => $km, 'accepted' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->get(route('fleet.kilometers.index', ['captura' => 'all', 'semana' => '2026-10-05']))->assertOk();
        $bars = $response->viewData('weeklyBars')->get($vehicle->id)->keyBy('week');
        $this->assertSame(1000, $bars['2026-09-07']['km']);
        $this->assertTrue($bars['2026-09-07']['review']);
        $this->assertNull($bars['2026-09-14']['km']);
        $this->assertNull($bars['2026-09-21']['km']);
        $this->assertSame(500, $bars['2026-09-28']['km']);
        $this->assertSame(700, $bars['2026-10-05']['km']);
        $this->assertSame(1000, $response->viewData('barMaximums')->get($vehicle->id));
        $this->assertSame(100, $response->viewData('barMaximums')->get($small->id));
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $vehicle->id, 'week_start' => '2026-09-07', 'accepted' => false, 'odometer_km' => 2000]);
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $vehicle->id, 'week_start' => '2026-09-14', 'odometer_km' => 0]);
    }

    private function workspace(string $role = 'administrator'): array
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);

        return [$tenant, $user];
    }

    private function vehicle(Tenant $tenant, string $plate): Vehicle
    {
        $id = DB::table('MBA_vehicles')->insertGetId(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'internal_code' => $plate, 'plate' => $plate, 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('VEH_maintenance_parameters')->insert(['vehicle_id' => $id, 'tenant_id' => $tenant->id, 'effective_from' => '2026-09-01', 'interval_km' => 10000, 'reason' => 'Referencia de prueba', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('VEH_maintenance_baselines')->insert(['vehicle_id' => $id, 'tenant_id' => $tenant->id, 'observed_week' => '2026-09-01', 'last_maintenance_km' => 10000, 'source_key' => 'test-'.$id, 'source' => 'Antecedente de prueba', 'created_at' => now(), 'updated_at' => now()]);

        return Vehicle::findOrFail($id);
    }

    private function reading(Vehicle $vehicle, int $km): array
    {
        return ['vehicle_id' => $vehicle->id, 'odometer_km' => $km, 'notes' => 'Registro semanal'];
    }
}
