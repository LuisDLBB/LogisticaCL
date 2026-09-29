<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('acuerdo_calendar_days', function (Blueprint $table) {
            $table->boolean('bloqueado')->default(false);
        });
        DB::table('acuerdo_service_rules')->whereIn(DB::raw('LOWER(servicio)'), [
            'apoyo alza', 'agencia apoyo alza',
        ])->get()->each(function (object $rule): void {
            $used = DB::table('acuerdos')->where('tenant_id', $rule->tenant_id)
                ->where('periodo', $rule->periodo)->where('servicio', $rule->servicio)->exists();
            if (! $used) {
                DB::table('acuerdo_service_rules')->where('id', $rule->id)->delete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('acuerdo_calendar_days', function (Blueprint $table) {
            $table->dropColumn('bloqueado');
        });
    }
};
