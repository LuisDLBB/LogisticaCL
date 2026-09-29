<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_order_mailings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('periodo', 6);
            $table->string('rut_proveedor', 20);
            $table->string('mode', 12);
            $table->string('recipient', 500);
            $table->string('subject', 200);
            $table->text('body');
            $table->json('oc_list');
            $table->string('status', 20);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'periodo', 'rut_proveedor', 'mode'], 'purchase_order_mailings_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_mailings');
    }
};
