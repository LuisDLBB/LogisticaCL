<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_oc_filenames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('company_code', 4);
            $table->string('service_scope', 100);
            $table->string('file_stem', 100);
            $table->timestamps();

            $table->unique(['provider_id', 'company_code', 'service_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_oc_filenames');
    }
};
