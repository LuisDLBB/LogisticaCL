<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'Base_Servicios',
        'Cierres_Pagos',
        'Maestro_Pagos',
        'Pago_Movimientos_Courier',
        'Proveedores_usuarios_4N',
        'Rutas_CV',
        'Visitas_Diarias',
        'acuerdo_calendar_days',
        'acuerdo_service_rules',
        'acuerdos',
        'apoyo_alzas',
        'bancos',
        'client_branch_contacts',
        'client_branches',
        'client_service_type',
        'cost_center_weight_rates',
        'cost_centers',
        'courier_import_errors',
        'courier_special_payments',
        'coverages',
        'envios_externos',
        'estados',
        'llave_centro_costos',
        'movimientos_courier',
        'peso_real',
        'provider_bank_accounts',
        'provider_oc_filenames',
        'purchase_order_mail_settings',
        'purchase_order_mailings',
        'ruta_cv_frequencies',
        'service_types',
        'tipo_envios',
        'tipos_cuenta_bancaria',
        'vehicles',
        'weight_transformations',
    ];

    public function up(): void
    {
        $this->rename(false);
    }

    public function down(): void
    {
        $this->rename(true);
    }

    private function rename(bool $reverse): void
    {
        $pairs = array_map(
            fn (string $name): array => $reverse ? ['PPR_'.$name, $name] : [$name, 'PPR_'.$name],
            self::TABLES,
        );

        foreach ($pairs as [$source, $target]) {
            if (! Schema::hasTable($source) || Schema::hasTable($target)) {
                throw new RuntimeException("No se puede renombrar {$source} a {$target}: falta la tabla de origen o ya existe la tabla de destino.");
            }
        }

        if (DB::getDriverName() === 'mysql') {
            $renames = array_map(
                fn (array $pair): string => sprintf('`%s` TO `%s`', $pair[0], $pair[1]),
                $pairs,
            );
            DB::statement('RENAME TABLE '.implode(', ', $renames));

            return;
        }

        foreach ($pairs as [$source, $target]) {
            Schema::rename($source, $target);
        }
    }
};
