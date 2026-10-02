<?php

namespace Tests\Feature;

use Database\Seeders\ProveedoresUsuarios4NSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProveedoresUsuarios4NTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplied_courier_provider_assignments_are_loaded_without_duplicates(): void
    {
        $this->assertTrue(Schema::hasColumns('PPR_Proveedores_usuarios_4N', [
            'RutProveedor', 'ComunaMatriz', 'NombreRepartidor', 'NuevoRutProveedor',
        ]));

        $this->seed(ProveedoresUsuarios4NSeeder::class);
        $this->seed(ProveedoresUsuarios4NSeeder::class);

        $this->assertSame(89, DB::table('PPR_Proveedores_usuarios_4N')->count());
        $this->assertDatabaseHas('PPR_Proveedores_usuarios_4N', [
            'RutProveedor' => '77346078-7', 'ComunaMatriz' => '4N RM',
            'NombreRepartidor' => 'Claudio Gonzalez', 'NuevoRutProveedor' => '78350442-1',
        ]);
        $this->assertDatabaseHas('PPR_Proveedores_usuarios_4N', [
            'RutProveedor' => '77346078-7', 'ComunaMatriz' => '4N Temuco',
            'NombreRepartidor' => 'Claudio Cuevas', 'NuevoRutProveedor' => '12538127-8',
        ]);
        $this->assertDatabaseHas('PPR_Proveedores_usuarios_4N', [
            'ComunaMatriz' => '4N Troncal Sur 3', 'NombreRepartidor' => 'Sergio Pino',
            'NuevoRutProveedor' => 'N/A',
        ]);
        $this->assertSame(0, DB::table('PPR_Proveedores_usuarios_4N')->whereIn('NuevoRutProveedor', ['0-1', '0-2'])->count());
    }
}
