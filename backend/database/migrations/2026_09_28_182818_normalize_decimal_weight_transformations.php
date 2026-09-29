<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['4.99' => [5, 4], '7.92' => [8, 7]] as $source => [$previous, $corrected]) {
            DB::table('weight_transformations')
                ->where('comparison_key', $source)
                ->where('transformed_weight', $previous)
                ->update(['transformed_weight' => $corrected, 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
