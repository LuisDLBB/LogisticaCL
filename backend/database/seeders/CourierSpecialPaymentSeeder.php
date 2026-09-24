<?php

namespace Database\Seeders;

use App\Models\CourierSpecialPayment;
use Illuminate\Database\Seeder;

class CourierSpecialPaymentSeeder extends Seeder
{
    public function run(): void
    {
        CourierSpecialPayment::factory()->count(5)->create();
    }
}
