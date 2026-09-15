<?php

namespace Database\Seeders;

use App\Models\CourierStatus;
use Illuminate\Database\Seeder;

class CourierStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Anulado' => false, 'En reparto' => true, 'En tránsito' => false, 'Entregado' => true, 'Fallido' => true, 'Pendiente' => false, 'Retirado' => false] as $name => $considerForPayment) {
            CourierStatus::query()->updateOrCreate(
                ['name' => $name],
                ['consider_for_payment' => $considerForPayment],
            );
        }
    }
}
