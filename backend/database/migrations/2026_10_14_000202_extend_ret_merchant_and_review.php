<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->json('original_data')->nullable();
            $table->string('requesting_area')->nullable();
            $table->string('pickup_kind')->nullable();
            $table->text('operations_notes')->nullable();
        });
        DB::table('service_requests')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('service_requests')->where('id', $row->id)->update(['original_data' => json_encode([
                    'service_date' => $row->requested_date_original, 'effective_address' => $row->address_snapshot,
                    'client_name_snapshot' => $row->client_name_snapshot, 'point_name_snapshot' => $row->point_name_snapshot,
                    'legacy' => true,
                ])]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', fn (Blueprint $table) => $table->dropColumn(['original_data', 'requesting_area', 'pickup_kind', 'operations_notes']));
    }
};
