<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderOcFilename;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderOcFilename>
 */
class ProviderOcFilenameFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'company_code' => fake()->randomElement(['4N', 'PMCB']),
            'service_scope' => 'General',
            'file_stem' => fake()->lexify('Proveedor????'),
        ];
    }
}
