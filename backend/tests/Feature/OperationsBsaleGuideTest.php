<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationsBsaleGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.bsale.production_token' => 'production-test-token',
            'services.bsale.demo_token' => 'demo-test-token',
        ]);
    }

    private function fixture(string $profile = 'Supervisor', int $lineCount = 1): array
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => $profile]);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $now = now();
        $load = DB::table('Ope_Cargas')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'source_type' => 'master',
            'filename' => 'maestro.xlsx', 'path' => 'tests/maestro.xlsx', 'sha256' => str_repeat('a', 64),
            'sheet' => 'Hoja1', 'mapping' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $lot = DB::table('Ope_Lotes')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'master_load_id' => $load,
            'operation_date' => '2026-10-08', 'name' => 'Proceso de prueba',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $origin = DB::table('Ope_Ubicaciones')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Bodega', 'address' => 'Origen vivo',
            'commune' => 'Quilicura', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $destination = DB::table('Ope_Ubicaciones')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Agencia', 'address' => 'Destino vivo',
            'commune' => 'Comuna viva', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $departure = DB::table('Ope_ProgramacionSalidas')->insertGetId([
            'lot_id' => $lot, 'departure_date' => '2026-10-09', 'name' => 'Salida viva',
            'role' => 'troncal', 'origin_id' => $origin, 'destination_id' => $destination,
            'plate' => 'LIVE-99', 'driver_name' => 'Chofer vivo', 'driver_rut' => '99-9',
            'version' => 1, 'status' => 'approved', 'approved_by' => $user->id, 'approved_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $lines = [];
        for ($number = 1; $number <= $lineCount; $number++) {
            $lines[] = [
                'agency' => 'Vallenar', 'merchant' => 'Cliente', 'service' => 'Normal',
                'customer_guide' => '', 'reference' => '',
                'description' => $lineCount === 1 ? 'Cliente: 2 bultos' : 'Glosa '.$number,
                'count' => $lineCount === 1 ? 2 : 1, 'weight' => '12.000',
                'packages' => $lineCount === 1 ? ['PKG-1', 'PKG-2'] : ['PKG-'.$number],
            ];
        }
        $snapshot = [
            'departure' => [
                'name' => 'Salida aprobada', 'departure_date' => '2026-10-08', 'role' => 'troncal',
                'plate' => 'ABCD12', 'driver_name' => 'María Soto', 'driver_rut' => '12345678-5',
            ],
            'origin' => ['name' => 'Bodega', 'address' => 'Galvarino 8481', 'commune' => 'Quilicura'],
            'destination' => ['name' => 'Agencia', 'address' => 'Victoria 1900', 'commune' => 'Vallenar'],
            'lines' => $lines,
            'packages' => [], 'count' => $lineCount === 1 ? 2 : $lineCount, 'weight' => '12.000',
        ];
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $guide = DB::table('Ope_Guias')->insertGetId([
            'departure_id' => $departure, 'version' => 1, 'snapshot' => $encoded,
            'sha256' => hash('sha256', $encoded), 'approved_by' => $user->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$tenant->id, $user->id, $departure, $guide, $snapshot];
    }

    private function bsaleResponse(): array
    {
        return [
            'id' => 501,
            'guide' => [
                'id' => 601, 'number' => 741,
                'urlPdf' => 'https://example.test/guide.pdf',
                'urlPublicView' => 'https://example.test/guide',
            ],
        ];
    }

    public function test_sends_only_approved_snapshot_data_with_production_token_and_stores_response(): void
    {
        [$tenant, , , $guide] = $this->fixture();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response($this->bsaleResponse(), 201)]);

        $this->get(route('operations.guides.show', $guide))->assertOk()->assertSee('Generar en Bsale');
        $this->post(route('operations.guides.bsale.store', $guide))->assertRedirect(route('operations.guides.show', $guide));

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.bsale.io/v1/shippings.json'
                && $request->hasHeader('access_token', 'production-test-token')
                && $payload === [
                    'documentTypeId' => 7,
                    'shippingTypeId' => 6,
                    'declareSii' => 0,
                    'sendEmail' => 0,
                    'emissionDate' => 1791417600,
                    'client' => [
                        'code' => '77346078-7',
                        'company' => '4 NORTES LOGISTICA SPA',
                        'activity' => 'Servicios De Logistica Y Distribucion',
                        'address' => 'Galvarino 8481, Bodega 17',
                        'municipality' => 'Quilicura',
                        'city' => 'Santiago',
                    ],
                    'address' => 'Victoria 1900',
                    'municipality' => 'Vallenar',
                    'city' => 'Vallenar',
                    'details' => [['comment' => "Cliente: 2 bultos\nHoja 1 de 1", 'quantity' => 2]],
                    'dynamicAttributes' => [
                        ['dynamicAttributeId' => 30, 'description' => 'ABCD12'],
                        ['dynamicAttributeId' => 31, 'description' => 'María Soto'],
                        ['dynamicAttributeId' => 32, 'description' => '12345678-5'],
                    ],
                ];
        });
        $this->assertDatabaseHas('Ope_GuiasBsale', [
            'tenant_id' => $tenant, 'guide_id' => $guide, 'version' => 1,
            'estado' => 'generada', 'shipping_id' => 501, 'document_id' => 601,
            'numero' => '741', 'url_pdf' => 'https://example.test/guide.pdf',
            'url_publica' => 'https://example.test/guide',
        ]);
        $this->get(route('operations.guides.show', $guide))
            ->assertOk()->assertSee('Hoja 1 de 1')->assertSee('741')->assertSee('Vista pública')
            ->assertDontSee('Generar en Bsale');
    }

    public function test_fifteen_lines_make_one_bsale_guide(): void
    {
        [, , , $oneSheetGuide] = $this->fixture(lineCount: 15);
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response($this->bsaleResponse())]);
        $this->post(route('operations.guides.bsale.store', $oneSheetGuide))->assertSessionHasNoErrors();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('Ope_GuiasBsale', [
            'guide_id' => $oneSheetGuide, 'sheet_number' => 1, 'sheet_count' => 1,
            'line_start' => 1, 'line_end' => 15, 'estado' => 'generada',
        ]);
    }

    public function test_sixteen_lines_make_two_guides_with_traceable_packages(): void
    {
        [$tenant, , , $twoSheetGuide] = $this->fixture(lineCount: 16);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->data();
            $number = count($requests);

            return Http::response([
                'id' => 500 + $number,
                'guide' => [
                    'id' => 600 + $number, 'number' => 740 + $number,
                    'urlPdf' => 'https://example.test/'.$number.'.pdf',
                    'urlPublicView' => 'https://example.test/'.$number,
                ],
            ], 201);
        });
        $this->post(route('operations.guides.bsale.store', $twoSheetGuide))->assertSessionHasNoErrors();

        $this->assertCount(2, $requests);
        $this->assertCount(15, $requests[0]['details']);
        $this->assertCount(1, $requests[1]['details']);
        $this->assertSame("Glosa 1\nHoja 1 de 2", $requests[0]['details'][0]['comment']);
        $this->assertSame('Glosa 2', $requests[0]['details'][1]['comment']);
        $this->assertSame("Glosa 16\nHoja 2 de 2", $requests[1]['details'][0]['comment']);
        $this->assertSame('Victoria 1900', $requests[1]['address']);
        $first = DB::table('Ope_GuiasBsale')->where(['guide_id' => $twoSheetGuide, 'sheet_number' => 1])->firstOrFail();
        $second = DB::table('Ope_GuiasBsale')->where(['guide_id' => $twoSheetGuide, 'sheet_number' => 2])->firstOrFail();
        $this->assertSame($tenant, $second->tenant_id);
        $this->assertSame([1, 15, 16, 16], [$first->line_start, $first->line_end, $second->line_start, $second->line_end]);
        $this->assertSame('741', $first->numero);
        $this->assertSame('742', $second->numero);
        $this->assertSame(15, DB::table('Ope_GuiasBsaleLineas')->where('emission_id', $first->id)->count());
        $this->assertSame(1, DB::table('Ope_GuiasBsaleLineas')->where('emission_id', $second->id)->count());
        $this->assertDatabaseHas('Ope_GuiasBsaleBultos', [
            'emission_id' => $first->id, 'line_number' => 15, 'tracking' => 'PKG-15',
        ]);
        $this->assertDatabaseHas('Ope_GuiasBsaleBultos', [
            'emission_id' => $second->id, 'line_number' => 16, 'tracking' => 'PKG-16',
        ]);
        $this->get(route('operations.guides.show', $twoSheetGuide))
            ->assertOk()->assertSee('Hoja 1 de 2')->assertSee('Hoja 2 de 2')
            ->assertSee('741')->assertSee('742')->assertSee('PKG-16');
        $this->get(route('operations.guides.bsale.index'))
            ->assertOk()->assertSee('Hoja 2 de 2')->assertSee('PKG-16');
    }

    public function test_thirty_one_lines_make_three_guides_in_order(): void
    {
        [, , , $guide] = $this->fixture(lineCount: 31);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->data();

            return Http::response($this->bsaleResponse(), 201);
        });

        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();

        $this->assertSame([15, 15, 1], array_map(fn (array $request): int => count($request['details']), $requests));
        $this->assertSame("Glosa 31\nHoja 3 de 3", $requests[2]['details'][0]['comment']);
        $this->assertDatabaseHas('Ope_GuiasBsale', [
            'guide_id' => $guide, 'sheet_number' => 3, 'sheet_count' => 3,
            'line_start' => 31, 'line_end' => 31, 'estado' => 'generada',
        ]);
        $this->assertDatabaseCount('Ope_GuiasBsale', 3);
    }

    public function test_uncertain_second_sheet_stops_the_third_until_manual_reconciliation(): void
    {
        [, , , $guide] = $this->fixture(lineCount: 31);
        Http::fakeSequence()
            ->push($this->bsaleResponse(), 201)
            ->push(['message' => 'Resultado desconocido'], 500)
            ->push([
                'id' => 503,
                'guide' => [
                    'id' => 603, 'number' => 743,
                    'urlPdf' => 'https://example.test/3.pdf',
                    'urlPublicView' => 'https://example.test/3',
                ],
            ], 201);

        $this->post(route('operations.guides.bsale.store', $guide))
            ->assertSessionHas('status', 'Revisa en Bsale si la guía fue creada antes de reintentar.');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('Ope_GuiasBsale', 2);
        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $guide, 'sheet_number' => 1, 'estado' => 'generada']);
        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $guide, 'sheet_number' => 2, 'estado' => 'incierta']);
        $this->get(route('operations.guides.show', $guide))
            ->assertOk()->assertSee('1 de 3 hojas generadas')->assertDontSee('Generar hojas restantes en Bsale');
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasErrors('bsale');
        Http::assertSentCount(2);

        $second = DB::table('Ope_GuiasBsale')->where(['guide_id' => $guide, 'sheet_number' => 2])->firstOrFail();
        $this->post(route('operations.guides.bsale.reconcile', $second->id), [
            'outcome' => 'created', 'shipping_id' => 502, 'document_id' => 602,
            'numero' => '742', 'url_pdf' => 'https://example.test/2.pdf',
            'url_publica' => 'https://example.test/2',
        ])->assertSessionHasNoErrors();
        $this->get(route('operations.guides.show', $guide))->assertSee('Generar hojas restantes en Bsale');
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();

        Http::assertSentCount(3);
        $this->assertDatabaseCount('Ope_GuiasBsale', 3);
        $this->assertDatabaseHas('Ope_GuiasBsale', [
            'guide_id' => $guide, 'sheet_number' => 3, 'line_start' => 31,
            'line_end' => 31, 'numero' => '743', 'estado' => 'generada',
        ]);
        $this->assertDatabaseHas('Ope_GuiasBsaleBultos', [
            'emission_id' => $second->id, 'line_number' => 30, 'tracking' => 'PKG-30',
        ]);
    }

    public function test_existing_emission_is_never_sent_twice(): void
    {
        [, , , $guide] = $this->fixture();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response($this->bsaleResponse())]);

        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasErrors('bsale');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('Ope_GuiasBsale', 1);
    }

    public function test_token_is_not_persisted_even_if_bsale_echoes_it(): void
    {
        [, , , $guide] = $this->fixture();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response([
            ...$this->bsaleResponse(), 'unexpected_echo' => 'production-test-token',
        ])]);

        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();

        $response = DB::table('Ope_GuiasBsale')->where('guide_id', $guide)->value('respuesta');
        $this->assertStringNotContainsString('production-test-token', $response);
        $this->assertStringContainsString('[REDACTED]', $response);
    }

    public function test_an_in_flight_record_blocks_sending(): void
    {
        [$tenant, $user, , $guide] = $this->fixture();
        Http::fake();
        DB::table('Ope_GuiasBsale')->insert([
            'tenant_id' => $tenant, 'guide_id' => $guide, 'version' => 1,
            'estado' => 'enviando', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasErrors('bsale');
        Http::assertNothingSent();
    }

    public function test_external_emission_survives_removal_of_internal_guide(): void
    {
        [, , , $guide] = $this->fixture();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response($this->bsaleResponse())]);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();

        DB::table('Ope_Guias')->where('id', $guide)->delete();

        $this->assertDatabaseMissing('Ope_Guias', ['id' => $guide]);
        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $guide, 'estado' => 'generada', 'document_id' => 601]);
        $emission = DB::table('Ope_GuiasBsale')->where('guide_id', $guide)->firstOrFail();
        $this->assertDatabaseHas('Ope_GuiasBsaleLineas', ['emission_id' => $emission->id, 'line_number' => 1]);
        $this->assertDatabaseHas('Ope_GuiasBsaleBultos', ['emission_id' => $emission->id, 'tracking' => 'PKG-2']);
        $this->get(route('operations.guides.bsale.index'))->assertOk()
            ->assertSee('guía interna limpiada')->assertSee('741')->assertSee('PKG-2');
    }

    public function test_connection_timeout_marks_uncertain_and_blocks_another_send(): void
    {
        [, , , $guide] = $this->fixture();
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            throw new ConnectionException('Connection timed out');
        });

        $this->post(route('operations.guides.bsale.store', $guide))
            ->assertRedirect(route('operations.guides.show', $guide))
            ->assertSessionHas('status', 'Revisa en Bsale si la guía fue creada antes de reintentar.');
        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $guide, 'estado' => 'incierta']);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasErrors('bsale');
        $this->assertSame(1, $requests);
    }

    public function test_http_error_and_missing_document_id_are_uncertain(): void
    {
        [, , , $guide] = $this->fixture();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response(['message' => 'Error'], 500)]);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHas('status', 'Revisa en Bsale si la guía fue creada antes de reintentar.');
        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $guide, 'estado' => 'incierta']);

        DB::table('Ope_GuiasBsale')->where('guide_id', $guide)->delete();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response(['id' => 88, 'guide' => ['number' => 99]], 200)]);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHas('status', 'Revisa en Bsale si la guía fue creada antes de reintentar.');
        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $guide, 'estado' => 'incierta']);
    }

    public function test_supervisor_can_reconcile_an_uncertain_emission_created_in_bsale(): void
    {
        [, , , $guide] = $this->fixture();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response(['error' => 'unknown'], 500)]);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();
        $emission = DB::table('Ope_GuiasBsale')->where('guide_id', $guide)->firstOrFail();
        $this->get(route('operations.guides.bsale.index'))->assertOk()->assertSee('Conciliar emisión incierta');

        $this->post(route('operations.guides.bsale.reconcile', $emission->id), [
            'outcome' => 'created', 'shipping_id' => 701, 'document_id' => 801,
            'numero' => '901', 'url_pdf' => 'https://example.test/manual.pdf',
            'url_publica' => 'https://example.test/manual',
        ])->assertRedirect(route('operations.guides.bsale.index'));

        $this->assertDatabaseHas('Ope_GuiasBsale', [
            'id' => $emission->id, 'estado' => 'generada', 'shipping_id' => 701,
            'document_id' => 801, 'numero' => '901',
            'url_pdf' => 'https://example.test/manual.pdf',
            'url_publica' => 'https://example.test/manual',
        ]);
        $this->assertDatabaseHas('Ope_Auditoria', ['entity' => 'bsale_emission', 'entity_id' => $emission->id, 'action' => 'Conciliar Bsale']);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasErrors('bsale');
        Http::assertSentCount(1);
    }

    public function test_manual_reconciliation_works_after_internal_guide_cleanup(): void
    {
        [$tenant, $user, , $guide] = $this->fixture();
        $emission = DB::table('Ope_GuiasBsale')->insertGetId([
            'tenant_id' => $tenant, 'guide_id' => $guide, 'version' => 1,
            'estado' => 'incierta', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('Ope_Guias')->where('id', $guide)->delete();

        $this->post(route('operations.guides.bsale.reconcile', $emission), [
            'outcome' => 'created', 'shipping_id' => 701, 'document_id' => 801,
            'numero' => '901', 'url_pdf' => 'https://example.test/manual.pdf',
            'url_publica' => 'https://example.test/manual',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('Ope_GuiasBsale', ['id' => $emission, 'estado' => 'generada', 'document_id' => 801]);
        $this->get(route('operations.guides.bsale.index'))->assertOk()->assertSee('guía interna limpiada')->assertSee('901');
    }

    public function test_confirmed_absence_enables_one_new_attempt(): void
    {
        [, , , $guide] = $this->fixture();
        Http::fakeSequence()->push(['error' => 'unknown'], 500)->push($this->bsaleResponse(), 200);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();
        $emission = DB::table('Ope_GuiasBsale')->where('guide_id', $guide)->firstOrFail();

        $this->post(route('operations.guides.bsale.reconcile', $emission->id), [
            'outcome' => 'not_created',
        ])->assertSessionHasErrors('confirmed_absent');
        $this->assertDatabaseHas('Ope_GuiasBsale', ['id' => $emission->id, 'estado' => 'incierta']);

        $this->post(route('operations.guides.bsale.reconcile', $emission->id), [
            'outcome' => 'not_created', 'confirmed_absent' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_GuiasBsale', ['id' => $emission->id, 'estado' => 'error']);

        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_GuiasBsale', ['id' => $emission->id, 'estado' => 'generada', 'document_id' => 601]);
        $this->assertDatabaseCount('Ope_GuiasBsale', 1);
        Http::assertSentCount(2);
    }

    public function test_historical_or_unapproved_version_cannot_be_emitted_and_new_approved_version_can(): void
    {
        [, $user, $departure, $guide] = $this->fixture();
        Http::fake(['api.bsale.io/v1/shippings.json' => Http::response($this->bsaleResponse())]);
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasNoErrors();
        DB::table('Ope_ProgramacionSalidas')->where('id', $departure)->update(['status' => 'draft', 'version' => 2]);

        $this->get(route('operations.guides.show', $guide))->assertOk()->assertDontSee('Generar en Bsale');
        $this->post(route('operations.guides.bsale.store', $guide))->assertSessionHasErrors('bsale');
        $encoded = DB::table('Ope_Guias')->where('id', $guide)->value('snapshot');
        $newGuide = DB::table('Ope_Guias')->insertGetId([
            'departure_id' => $departure, 'version' => 2, 'snapshot' => $encoded,
            'sha256' => hash('sha256', $encoded), 'approved_by' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post(route('operations.guides.bsale.store', $newGuide))->assertSessionHasErrors('bsale');
        DB::table('Ope_ProgramacionSalidas')->where('id', $departure)->update(['status' => 'approved']);
        $this->post(route('operations.guides.bsale.store', $newGuide))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $guide, 'version' => 1, 'estado' => 'generada']);
        $this->assertDatabaseHas('Ope_GuiasBsale', ['guide_id' => $newGuide, 'version' => 2, 'estado' => 'generada']);
        Http::assertSentCount(2);
    }

    public function test_operator_cannot_see_or_use_emission_action(): void
    {
        [, , , $guide] = $this->fixture('Operario');
        Http::fake();

        $this->get(route('operations.guides.show', $guide))->assertOk()->assertDontSee('Generar en Bsale');
        $this->get(route('operations.guides.bsale.index'))->assertForbidden();
        $this->post(route('operations.guides.bsale.store', $guide))->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('Ope_GuiasBsale', 0);
    }

    public function test_operator_cannot_reconcile_an_uncertain_emission(): void
    {
        [$tenant, $user, , $guide] = $this->fixture('Operario');
        $emission = DB::table('Ope_GuiasBsale')->insertGetId([
            'tenant_id' => $tenant, 'guide_id' => $guide, 'version' => 1,
            'estado' => 'incierta', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('operations.guides.bsale.reconcile', $emission), [
            'outcome' => 'not_created', 'confirmed_absent' => '1',
        ])->assertForbidden();
        $this->assertDatabaseHas('Ope_GuiasBsale', ['id' => $emission, 'estado' => 'incierta']);
    }
}
