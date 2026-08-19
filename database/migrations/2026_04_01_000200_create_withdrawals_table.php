<?php

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Money on its way out.
         *
         * A withdrawal reserves against the available balance from the moment
         * it is requested — see WithdrawalService — so a seller cannot request
         * the same money twice while the first request is still in flight.
         * The ledger entry itself is only written once the gateway has taken
         * the transfer.
         */
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Restrict, not cascade: an account cannot be deleted out from
            // under a payout that references it.
            $table->foreignId('payout_account_id')->constrained()->restrictOnDelete();

            $table->string('reference', 40)->unique();

            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3)->default('NGN');

            $table->enum('status', WithdrawalStatus::values())
                ->default(WithdrawalStatus::Requested->value)
                ->index();

            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable()->index();

            // Why an administrator approved, rejected, or what the gateway said.
            $table->text('admin_note')->nullable();
            $table->text('failure_reason')->nullable();

            // The ledger row this withdrawal produced, once it has one.
            $table->foreignId('wallet_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // The shape the reserved-funds query takes on every request.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
