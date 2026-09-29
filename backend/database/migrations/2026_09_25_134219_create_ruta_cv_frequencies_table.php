<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ruta_cv_frequencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('name_key', 100);
            $table->json('weekdays');
            $table->timestamps();
            $table->unique(['tenant_id', 'name_key']);
        });

        $frequencies = DB::table('Rutas_CV')->select('tenant_id', 'frecuencia')->distinct()->get();
        foreach ($frequencies as $frequency) {
            $name = trim((string) $frequency->frecuencia);
            $key = Str::slug($name);
            $weekdays = match ($key) {
                'ruta-lu-a-vi' => [1, 2, 3, 4, 5],
                'ruta-lu-mi-vi' => [1, 3, 5],
                'ruta-ma-ju-vi' => [2, 4, 5],
                default => [],
            };
            DB::table('ruta_cv_frequencies')->insertOrIgnore([
                'tenant_id' => $frequency->tenant_id,
                'name' => $name,
                'name_key' => $key,
                'weekdays' => json_encode($weekdays, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ruta_cv_frequencies');
    }
};
