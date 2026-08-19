<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_delivery_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('seller_profiles')->cascadeOnDelete();

            // What the seller charges to deliver to this state.
            $table->string('state', 64);
            $table->unsignedBigInteger('fee_kobo');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['seller_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_delivery_rates');
    }
};
