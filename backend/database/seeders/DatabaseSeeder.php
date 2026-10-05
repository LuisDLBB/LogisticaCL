<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(MasterDataSeeder::class);
        $this->call(OperationsTransportSeeder::class);
        $this->call(ProviderPaymentTermsSeeder::class);
        $this->call(ProviderOcFilenameSeeder::class);
        $this->call(TipoEnvioSeeder::class);
        $this->call(BancoSeeder::class);
        $this->call(TipoCuentaBancariaSeeder::class);
        $this->call(ProveedoresUsuarios4NSeeder::class);
        $this->call(CalamaProviderTransitionSeeder::class);
        $this->call(ClaudioCuevasPaymentKeysSeeder::class);
    }
}
