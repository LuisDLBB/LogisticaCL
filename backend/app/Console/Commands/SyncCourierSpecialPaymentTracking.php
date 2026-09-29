<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentTrackingMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('courier-special:sync-tracking {period?}')]
#[Description('Cruza cliente y servicio de pagos especiales con movimientos por seguimiento')]
class SyncCourierSpecialPaymentTracking extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CourierSpecialPaymentTrackingMatcher $matcher): int
    {
        $period = $this->argument('period');
        if ($period !== null && ! preg_match('/^\d{6}-Especiales$/', $period)) {
            $this->error('El período debe tener formato AAAAMM-Especiales.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $result = $matcher->sync($tenant->id, $period);
        $this->info("{$result['matched']} códigos cruzados; {$result['updated']} pagos especiales actualizados.");

        return self::SUCCESS;
    }
}
