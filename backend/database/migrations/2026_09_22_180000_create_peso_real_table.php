<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peso_real', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('seguimiento_paquete', 50);
            $table->unsignedInteger('peso_real');
            $table->string('codigo_seguimiento', 50);
            $table->date('fecha_proceso');
            $table->string('comerciante', 255);
            $table->string('servicio', 160);
            $table->timestamps();
            $table->index(['tenant_id', 'fecha_proceso']);
            $table->index(['tenant_id', 'seguimiento_paquete']);
            $table->index(['tenant_id', 'comerciante']);
            $table->index(['tenant_id', 'servicio']);
        });

        if (! app()->environment('testing')) {
            $tenantId = DB::table('tenants')->where('code', '4N')->value('id');
            if (! $tenantId) {
                throw new \RuntimeException('No existe el tenant 4N requerido para importar Peso_Real.');
            }
            $path = database_path('data/peso_real.tsv.gz');
            $handle = gzopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('No se pudo abrir el archivo inicial de Peso_Real.');
            }
            $months = ['ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04', 'may' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12'];
            $now = now();
            $batch = [];
            fgetcsv($handle, 0, "\t");
            while (($row = fgetcsv($handle, 0, "\t")) !== false) {
                if (count($row) < 6) {
                    continue;
                }
                if (! preg_match('/^(\d{2})-([a-z]{3})-(\d{2})$/u', mb_strtolower(trim($row[3])), $date) || ! isset($months[$date[2]])) {
                    throw new \RuntimeException('FechaProceso inválida en Peso_Real: '.($row[3] ?? ''));
                }
                $batch[] = [
                    'tenant_id' => $tenantId,
                    'seguimiento_paquete' => trim($row[0]),
                    'peso_real' => (int) str_replace(',', '.', trim($row[1])),
                    'codigo_seguimiento' => trim($row[2]),
                    'fecha_proceso' => '20'.$date[3].'-'.$months[$date[2]].'-'.$date[1],
                    'comerciante' => trim($row[4]),
                    'servicio' => trim($row[5]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($batch) === 1000) {
                    DB::table('peso_real')->insert($batch);
                    $batch = [];
                }
            }
            gzclose($handle);
            if ($batch !== []) {
                DB::table('peso_real')->insert($batch);
            }
            $duplicates = DB::table('peso_real')->select('tenant_id', 'seguimiento_paquete')
                ->groupBy('tenant_id', 'seguimiento_paquete')->havingRaw('COUNT(*) > 1')->get();
            foreach ($duplicates as $duplicate) {
                $keepId = DB::table('peso_real')->where('tenant_id', $duplicate->tenant_id)
                    ->where('seguimiento_paquete', $duplicate->seguimiento_paquete)
                    ->orderByDesc('fecha_proceso')->orderByDesc('id')->value('id');
                DB::table('peso_real')->where('tenant_id', $duplicate->tenant_id)
                    ->where('seguimiento_paquete', $duplicate->seguimiento_paquete)->where('id', '<>', $keepId)->delete();
            }
        }
        Schema::table('peso_real', fn (Blueprint $table) => $table->unique(['tenant_id', 'seguimiento_paquete']));
    }

    public function down(): void
    {
        Schema::dropIfExists('peso_real');
    }
};
