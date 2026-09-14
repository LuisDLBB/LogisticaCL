<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'tax_id' => fake()->numerify('########-#'),
            'tax_id_number' => fake()->numerify('########'),
            'tax_id_check_digit' => fake()->randomElement(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'K']),
            'legal_name' => fake()->company(),
            'operational_name' => fake()->company(),
            'operator_type' => fake()->randomElement(['RM', 'Regiones']),
            'tax_document_type' => 'Factura',
            'commercial_address' => fake()->streetAddress(),
            'commercial_commune_name' => fake()->city(),
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->phoneNumber(),
            'contact_email' => fake()->companyEmail(),
            'is_active' => true,
        ];
    }
}
