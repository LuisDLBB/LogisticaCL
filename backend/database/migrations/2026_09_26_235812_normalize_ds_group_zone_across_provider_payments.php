<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $providerIds = DB::table('providers')->where('tax_id_number', '77201525')->pluck('id');
        $taxIds = ['77201525-9', '77.201.525-9'];

        foreach ([
            'coverages' => ['zone', 'provider_tax_id'],
            'Rutas_CV' => ['zona', 'rut_proveedor'],
            'Base_Servicios' => ['zona', 'rut_proveedor'],
            'acuerdos' => ['zona', 'rut_proveedor_origen'],
            'Pago_Movimientos_Courier' => ['zona', 'rut_proveedor'],
        ] as $table => [$zoneColumn, $taxColumn]) {
            DB::table($table)->where(function (Builder $query) use ($providerIds, $taxIds, $taxColumn): void {
                $query->whereIn($taxColumn, $taxIds)->orWhereIn('provider_id', $providerIds);
            })->update([$zoneColumn => 'RM']);
        }

        DB::table('Base_Servicios')->whereIn('rut_proveedor_origen', $taxIds)->update(['zona' => 'RM']);
    }

    public function down(): void {}
};
