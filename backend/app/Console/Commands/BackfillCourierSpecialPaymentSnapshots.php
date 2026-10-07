<?php

namespace App\Console\Commands;

use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierSpecialPayment;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

#[Signature('courier-special:backfill-snapshots {period} {--backup=}')]
#[Description('Recupera las copias previas de Especiales ya finalizados desde un respaldo SQLite')]
class BackfillCourierSpecialPaymentSnapshots extends Command
{
    public function handle(): int
    {
        $period = (string) $this->argument('period');
        if (! preg_match('/^\d{6}-Especiales$/', $period)) {
            $this->error('El período debe tener formato AAAAMM-Especiales.');

            return self::FAILURE;
        }
        $backupPath = (string) ($this->option('backup') ?: database_path('database_before_paid_tracking_cleanup_20260924.sqlite'));
        if (! is_file($backupPath)) {
            $this->error('No se encontró el respaldo SQLite indicado.');

            return self::FAILURE;
        }

        try {
            $backup = new PDO('sqlite:'.$backupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $backupTable = $backup->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'PPR_Pago_Movimientos_Courier'")->fetchColumn()
                ? 'PPR_Pago_Movimientos_Courier'
                : 'Pago_Movimientos_Courier';
            $query = $backup->prepare("SELECT * FROM {$backupTable} WHERE tenant_id = ? AND seguimiento_paquete = ?");
            $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
            $specials = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
                ->where('periodo', $period)->whereNotNull('finalized_at')
                ->whereNull('payment_before_finalization')->orderBy('id')->get();
            $snapshots = [];
            $synthetic = 0;
            foreach ($specials as $special) {
                $payment = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
                    ->where('nombre_proceso', $period)
                    ->where('seguimiento_paquete', $special->finalized_tracking_number)->firstOrFail();
                $movement = CourierMovement::query()->where('tenant_id', $tenant->id)
                    ->whereKey($payment->courier_movement_id)->firstOrFail();
                if ($movement->nombre_proceso === $period && $movement->source_system === 'Especiales') {
                    $synthetic++;

                    continue;
                }
                $query->execute([$tenant->id, $payment->seguimiento_paquete]);
                $old = $query->fetch(PDO::FETCH_ASSOC);
                if (! $old || $query->fetch(PDO::FETCH_ASSOC)
                    || (int) $old['id'] !== $payment->id
                    || (int) $old['courier_movement_id'] !== $movement->id
                    || $old['created_at'] !== $payment->getRawOriginal('created_at')
                    || $old['updated_at'] >= $special->finalized_at->toDateTimeString()
                    || $payment->updated_at?->toDateTimeString() !== $special->finalized_at->toDateTimeString()) {
                    $this->error("El respaldo no coincide con el registro {$special->id}. No se guardó ninguna copia.");

                    return self::FAILURE;
                }
                foreach (['client_id', 'provider_id', 'service_type_id', 'service_code', 'service_name'] as $column) {
                    $old[$column] ??= null;
                }
                $old['id'] = (int) $old['id'];
                $old['tenant_id'] = (int) $old['tenant_id'];
                $old['courier_movement_id'] = (int) $old['courier_movement_id'];
                $snapshots[$special->id] = $old;
            }

            DB::transaction(function () use ($snapshots, $tenant, $period): void {
                foreach ($snapshots as $id => $snapshot) {
                    CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
                        ->where('periodo', $period)->whereKey($id)
                        ->whereNull('payment_before_finalization')
                        ->update(['payment_before_finalization' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                }
            });
            $this->info(count($snapshots).' copias previas recuperadas; '.$synthetic.' movimientos Especiales nuevos no necesitan copia.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
