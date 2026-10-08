<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Ope_GuiasBsale', function (Blueprint $table): void {
            $table->dropUnique(['guide_id', 'version']);
            $table->unsignedInteger('sheet_number')->default(1);
            $table->unsignedInteger('sheet_count')->default(1);
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->unique(['guide_id', 'version', 'sheet_number']);
        });

        Schema::create('Ope_GuiasBsaleLineas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emission_id')->constrained('Ope_GuiasBsale')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->json('line_snapshot');
            $table->unique(['emission_id', 'line_number']);
        });

        Schema::create('Ope_GuiasBsaleBultos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emission_id')->constrained('Ope_GuiasBsale')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('tracking', 100);
            $table->unique(['emission_id', 'line_number', 'tracking']);
            $table->index('tracking');
        });

        foreach (DB::table('Ope_GuiasBsale')->get(['id', 'guide_id']) as $emission) {
            $snapshot = DB::table('Ope_Guias')->where('id', $emission->guide_id)->value('snapshot');
            $lines = $snapshot ? json_decode($snapshot, true)['lines'] ?? [] : [];
            if (! is_array($lines) || $lines === []) {
                continue;
            }

            DB::table('Ope_GuiasBsale')->where('id', $emission->id)->update([
                'line_start' => 1,
                'line_end' => count($lines),
            ]);
            foreach (array_values($lines) as $index => $line) {
                $lineNumber = $index + 1;
                DB::table('Ope_GuiasBsaleLineas')->insert([
                    'emission_id' => $emission->id,
                    'line_number' => $lineNumber,
                    'line_snapshot' => json_encode($line, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
                foreach (array_unique($line['packages'] ?? []) as $tracking) {
                    DB::table('Ope_GuiasBsaleBultos')->insert([
                        'emission_id' => $emission->id,
                        'line_number' => $lineNumber,
                        'tracking' => $tracking,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::table('Ope_GuiasBsaleLineas')->exists()
            || DB::table('Ope_GuiasBsale')->where('sheet_number', '>', 1)->exists()) {
            throw new RuntimeException('Conserva las asociaciones de Bsale antes de revertir esta migración.');
        }

        Schema::dropIfExists('Ope_GuiasBsaleBultos');
        Schema::dropIfExists('Ope_GuiasBsaleLineas');
        Schema::table('Ope_GuiasBsale', function (Blueprint $table): void {
            $table->dropUnique(['guide_id', 'version', 'sheet_number']);
            $table->dropColumn(['sheet_number', 'sheet_count', 'line_start', 'line_end']);
            $table->unique(['guide_id', 'version']);
        });
    }
};
