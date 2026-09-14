<?php

namespace Database\Factories;

use App\Models\CourierMovement;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierMovement>
 */
class CourierMovementFactory extends Factory
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
            'client_id' => null,
            'source_system' => 'Geolize',
            'fecha' => now()->toDateString(),
            'tracking_number' => fake()->unique()->bothify('4N############-###'),
            'tracking_code' => fake()->bothify('4N############'),
            'external_code' => fake()->bothify('EXT-#####'),
            'peso_real' => null,
            'status' => 'Entregado',
            'delivery_attempts' => 0,
            'merchant_name' => fake()->company(),
            'service_name' => 'Servicio Standar',
            'campaign_name' => fake()->word(),
            'recipient_name' => fake()->name(),
            'recipient_address' => fake()->address(),
            'destination_commune_name' => fake()->city(),
            'declared_value' => '0.00',
            'received_at' => now()->subDay(),
            'estimated_delivery_date' => now()->toDateString(),
            'merchant_pickup' => false,
            'pickup_warehouse_name' => fake()->city(),
        ];
    }
}
