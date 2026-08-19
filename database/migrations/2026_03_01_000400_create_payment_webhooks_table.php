<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every webhook the platform receives, valid or not, with its raw body.
         *
         * When money is disputed months later, the argument is settled by what
         * the gateway actually sent — not by what the application decided to
         * record about it. Rejected deliveries are kept too: a run of invalid
         * signatures is exactly what somebody probing the endpoint looks like.
         */
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32)->index();

            // The gateway's own event id where it sends one. Unique per gateway
            // so a duplicate delivery is rejected by the database rather than
            // by a check that might race.
            $table->string('event_id')->nullable();
            $table->string('event_type', 64)->nullable();
            $table->string('gateway_reference')->nullable()->index();

            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 64)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
            $table->index(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
