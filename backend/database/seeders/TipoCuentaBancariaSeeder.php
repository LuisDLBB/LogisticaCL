<?php

namespace Database\Seeders;

use App\Models\ProviderBankAccount;
use App\Models\TipoCuentaBancaria;
use Illuminate\Database\Seeder;

class TipoCuentaBancariaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accountTypes = [
            1 => 'Cuenta Corriente',
            2 => 'Cuenta Vista',
            3 => 'Cuenta RUT',
            4 => 'Cuenta Ahorro',
        ];

        foreach ($accountTypes as $accountTypeId => $accountType) {
            TipoCuentaBancaria::query()->updateOrCreate(
                ['id_tipo_cuenta' => $accountTypeId],
                ['tipo_cuenta' => $accountType, 'is_active' => true],
            );
        }

        ProviderBankAccount::query()->where('bank_name', 'Copeuch')->update(['bank_name' => 'Coopeuch']);
        ProviderBankAccount::query()
            ->whereIn('bank_name', ['N/A', 'No Aplica'])
            ->orWhereIn('account_type', ['N/A', 'No Aplica'])
            ->delete();
    }
}
