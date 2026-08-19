<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The ledger.
         *
         * There is deliberately no balance column here or anywhere else. Every
         * balance in this application is derived by summing these rows, so a
         * balance cannot drift from the entries that produced it, and a
         * correction is a new row rather than an edit to an old one.
         */
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            // Null means the platform's own account, which is where commission
            // is recorded. A real row would be an account somebody could sign
            // in to, which the platform's books should not be.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('sub_order_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('type', LedgerType::values());

            // Signed: a payout is positive, a withdrawal or reversal negative.
            $table->bigInteger('amount_kobo');

            $table->enum('state', LedgerState::values())->index();

            $table->string('description');
            $table->json('meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The shape every balance query takes.
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'type', 'state']);
            $table->index(['sub_order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
