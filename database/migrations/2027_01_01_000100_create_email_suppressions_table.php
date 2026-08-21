<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses the platform must stop writing to.
 *
 * Keyed by the address rather than by user, because most of the mail this
 * platform sends does not go to an account: a consultation is booked by email
 * by somebody who never registers, a quotation proposal goes to a client, the
 * contact form replies to a stranger. A suppression list hanging off `users`
 * would miss all of them and keep hammering a dead address on their behalf.
 *
 * The reason this exists at all: a provider's reputation score is shared across
 * every message the platform sends. Retrying a hard-bounced address forever
 * drags that score down for everybody — the seller waiting on an order
 * notification included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();

            // Stored lowercased and unique: the same address bouncing twice is
            // one suppression, not two.
            $table->string('email')->unique();

            // 'bounce' is the address being wrong. 'complaint' is a person
            // pressing "this is spam" — a working address we must stop writing
            // to, which is a different thing and worth telling apart.
            $table->string('type', 32);

            $table->string('provider', 32)->nullable();
            $table->text('reason')->nullable();

            // Kept so an administrator can see what the provider actually said,
            // which is the difference between "mailbox full" and "no such user".
            $table->json('payload')->nullable();

            $table->timestamp('suppressed_at');

            /*
             * Set when somebody lifts the suppression by hand. Kept as a row
             * rather than deleted, so the history of an address that has
             * bounced and been cleared twice is still visible.
             */
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['type', 'suppressed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
