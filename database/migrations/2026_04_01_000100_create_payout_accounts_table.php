<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Where somebody's money goes.
         *
         * The account name is never typed by the user: it comes back from the
         * bank through the gateway's resolution endpoint, and `is_verified`
         * records that it did. An unverified account is never paid.
         */
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('bank_code', 16);
            $table->string('bank_name');
            $table->string('account_number', 20);

            // As the bank returned it, not as the user typed it.
            $table->string('account_name');

            $table->boolean('is_verified')->default(false);

            // The gateway's own handle for this recipient, so a repeat payout
            // does not have to create one again.
            $table->string('gateway_recipient_code')->nullable();
            $table->string('gateway')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The same account cannot be added twice by the same person.
            $table->unique(['user_id', 'bank_code', 'account_number'], 'payout_accounts_unique_per_user');
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};
