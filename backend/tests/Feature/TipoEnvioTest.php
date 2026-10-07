<?php

namespace Tests\Feature;

use Database\Seeders\TipoEnvioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TipoEnvioTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_types_are_seeded_with_the_requested_information(): void
    {
        $this->seed(TipoEnvioSeeder::class);

        $this->assertDatabaseCount('PPR_tipo_envios', 3);
        $this->assertDatabaseHas('PPR_tipo_envios', [
            'tipo_envio' => 'B2C',
            'glosa' => 'Business to Business',
            'detalle' => 'Venta al consumidor final con foco en volumen',
            'ejemplo' => 'Conversión, logística, servicio y devoluciones',
        ]);
        $this->assertDatabaseHas('PPR_tipo_envios', ['tipo_envio' => 'B2B']);
        $this->assertDatabaseHas('PPR_tipo_envios', ['tipo_envio' => 'D2C']);
    }
}
