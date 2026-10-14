<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('VEH_maintenance_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->foreignId('actor_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'normalized_name']);
        });
        foreach (DB::table('vehicle_maintenances')->select('tenant_id', 'maintenance_type')->distinct()->get() as $row) {
            $name = trim(preg_replace('/\s+/u', ' ', $row->maintenance_type));
            if ($name === '') {
                continue;
            }
            $key = Str::upper(Str::ascii($name));
            $labels = ['MANTENCION' => 'Mantención', 'REPARACION' => 'Reparación', 'NEUMATICOS' => 'Neumáticos', 'REPUESTO' => 'Repuesto', 'PLATAFORMA' => 'Plataforma', 'ELECTRICO' => 'Eléctrico', 'PARABRISAS' => 'Parabrisas', 'ASISTENCIA' => 'Asistencia', 'GARANTIA' => 'Garantía'];
            DB::table('VEH_maintenance_types')->insertOrIgnore(['tenant_id' => $row->tenant_id, 'name' => $labels[$key] ?? $name, 'normalized_name' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_maintenance_types');
    }
};
