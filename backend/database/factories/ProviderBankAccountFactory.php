<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderBankAccount>
 */
class ProviderBankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'account_holder_name' => fake()->name(),
            'account_holder_tax_id' => fake()->numerify('########-#'),
            'bank_name' => fake()->randomElement(['Banco Estado', 'Banco de Chile', 'BCI']),
            'account_type' => fake()->randomElement(['Cuenta Corriente', 'Cuenta Vista']),
            'account_number' => fake()->numerify('##########'),
            'is_primary' => true,
            'is_active' => true,
        ];
    }
}
