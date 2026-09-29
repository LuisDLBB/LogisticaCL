<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $patterns = [
            'Ruta Lu/Mi/Vi' => [1, 3, 5],
            'Ruta Ma/Ju/Vi' => [2, 4, 5],
        ];

        foreach ($patterns as $name => $weekdays) {
            DB::table('ruta_cv_frequencies')->where('name', $name)->where('weekdays', '[]')
                ->update(['weekdays' => json_encode($weekdays, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }

        $routes = DB::table('Rutas_CV')->whereIn('frecuencia', array_keys($patterns))
            ->where('origen', 'generado')->whereNull('closed_at')->where('dias', '[]')->get();
        foreach ($routes as $route) {
            if (DB::table('Cierres_Pagos')->where('tenant_id', $route->tenant_id)
                ->where('periodo', $route->periodo)->exists()) {
                continue;
            }

            $month = CarbonImmutable::create((int) substr($route->periodo, 0, 4), (int) substr($route->periodo, 4, 2), 1);
            $days = [];
            for ($day = 1; $day <= $month->daysInMonth; $day++) {
                if (in_array($month->setDay($day)->dayOfWeekIso, $patterns[$route->frecuencia], true)) {
                    $days[] = $day;
                }
            }

            $total = $route->tipo_cobro === 'fijo'
                ? (int) $route->monto_fijo
                : max(0, count($days) - (int) $route->inasistencia) * (int) $route->valor;
            DB::table('Rutas_CV')->where('id', $route->id)->whereNull('closed_at')->where('dias', '[]')
                ->update(['dias' => json_encode($days, JSON_THROW_ON_ERROR),
                    'total_mensual' => $total, 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
