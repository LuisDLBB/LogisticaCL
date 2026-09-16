<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Coverage;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Modules\ProviderPayments\ParameterReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CourierParameterReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_north_is_the_default_company_for_parameter_review(): void
    {
        $this->withSession(['courier_review' => [
            'file' => 'prueba.csv',
            'records' => 0,
            'groups' => [],
            'missing_columns' => [],
        ]])->get(route('provider-payments.courier-movements.review-parameters'))
            ->assertOk()
            ->assertSee('Empresa de prueba:')
            ->assertSee('4N')
            ->assertDontSee('Selecciona una empresa');
    }

    public function test_initial_companies_are_registered_in_the_tenant_master(): void
    {
        $this->assertDatabaseHas('tenants', [
            'code' => '4N',
            'tax_id' => '77346078-7',
            'legal_name' => '4 Nortes Logistica SPA',
            'business_activity' => 'Logistica',
        ]);
        $this->assertDatabaseHas('tenants', [
            'code' => 'PMCB',
            'tax_id' => '77639015-1',
            'legal_name' => 'Transportes y Distribucion PMCB SPA',
            'business_activity' => 'Transporte de Carga por Carretera',
        ]);
    }

    public function test_windows_csv_accents_survive_json_session_storage(): void
    {
        $csv = mb_convert_encoding("Seguimiento paquete,Comerciante,Servicio,Comuna,Peso,Estado\n4N20260916A,Diseño,Distribución,Ñuñoa,5,En tránsito\n", 'Windows-1252', 'UTF-8');
        $this->post(route('provider-payments.courier-movements.validate'), [
            'file' => UploadedFile::fake()->createWithContent('movimientos.csv', $csv),
        ])->assertOk()->assertSee('En tránsito');
        $snapshot = session('courier_review');
        $restored = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($snapshot, $restored);
        $this->withSession(['courier_review' => $restored])->get(route('provider-payments.courier-movements.review-parameters'))
            ->assertOk()->assertSee('Diseño')->assertSee('Ñuñoa')->assertDontSee('Necesitamos validar');
    }

    public function test_validation_preserves_grouped_values_and_review_shows_them(): void
    {
        $csv = "Seguimiento paquete,Comerciante,Servicio,Comuna,Peso\n4N20260916A,Cliente A,Normal,Temuco,5\n4N20260916B,Cliente A,Normal,Temuco,5\n4N20260916C,Cliente B,Especial,TEMUCO,\n";
        $this->post(route('provider-payments.courier-movements.validate'), [
            'file' => UploadedFile::fake()->createWithContent('movimientos.csv', $csv),
        ])->assertOk()->assertSessionHas('courier_review.records', 3);

        $response = $this->get(route('provider-payments.courier-movements.review-parameters'));
        $response->assertOk()->assertSee('Cliente A')->assertSee('TEMUCO')
            ->assertSee('Empresa de prueba:')->assertSee('4N')
            ->assertSee('Ingresar este valor en Comerciante (Pila)');
    }

    public function test_comparison_is_scoped_and_preserves_exact_commune_names(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '1-9', 'tax_id_number' => '1', 'tax_id_check_digit' => '9', 'source_merchant_name' => 'Cliente A', 'commercial_name' => 'Comercial Cliente A', 'legal_name' => 'Cliente A Ltda']);
        $service = ServiceType::factory()->create(['name' => 'Normal']);
        $client->serviceTypes()->attach($service->id, ['is_active' => true]);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Temuco', 'zone' => 'Regiones', 'provider_tax_id' => '1-9', 'is_active' => true]);
        Coverage::create(['tenant_id' => $other->id, 'commune_name' => 'Otra', 'zone' => 'Regiones', 'provider_tax_id' => '2-7', 'is_active' => true]);
        $input = [
            'clients' => [['values' => ['Cliente A'], 'count' => 2]],
            'services' => [['values' => ['Cliente A', 'Normal'], 'count' => 2], ['values' => ['Cliente A', 'Desconocido'], 'count' => 1]],
            'coverages' => [['values' => ['Temuco'], 'count' => 2], ['values' => ['TEMUCO'], 'count' => 3], ['values' => ['Otra'], 'count' => 4]],
            'weights' => [['values' => [''], 'count' => 1]],
        ];
        $groups = (new ParameterReview)->compare($input, $tenant->id);
        $this->assertSame([], $groups[0]['items']);
        $this->assertCount(1, $groups[1]['items']);
        $this->assertSame(7, $groups[2]['affected']);
        $this->assertStringContainsString('1 por defecto', $groups[3]['items'][0]['action']);
        $this->assertDatabaseCount('movimientos_courier', 0);
    }

    public function test_client_match_uses_comerciante_pila_instead_of_business_names(): void
    {
        $tenant = Tenant::factory()->create();
        Client::create([
            'tenant_id' => $tenant->id,
            'tax_id' => '12345678-9',
            'tax_id_number' => '12345678',
            'tax_id_check_digit' => '9',
            'source_merchant_name' => 'Pila Exacta',
            'commercial_name' => 'Otra Razón Comercial',
            'legal_name' => 'Otra Razón Social Ltda',
        ]);

        $input = [
            'clients' => [
                ['values' => ['Pila Exacta'], 'count' => 3],
                ['values' => ['Otra Razón Comercial'], 'count' => 2],
            ],
        ];
        $groups = (new ParameterReview)->compare($input, $tenant->id);

        $this->assertCount(1, $groups[0]['items']);
        $this->assertSame('Otra Razón Comercial', $groups[0]['items'][0]['values'][0]);
        $this->assertStringContainsString('Comerciante (Pila)', $groups[0]['items'][0]['action']);
    }

    public function test_missing_previous_validation_is_explained(): void
    {
        $this->get(route('provider-payments.courier-movements.review-parameters'))
            ->assertOk()->assertSee('Necesitamos validar el archivo nuevamente');
    }
}
