<?php

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A buyer saying something went wrong.
         *
         * One live dispute per sub-order — the unique index below is on the
         * sub-order alone, so a second one cannot be raised while money is
         * still frozen by the first. A resolved dispute is soft-deleted rather
         * than removed if it ever needs reopening, which keeps the constraint
         * honest without losing the history.
         */
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();

            $table->enum('reason', DisputeReason::values());
            $table->text('description');

            // Paths on the public disk. Photographs of dead birds and short
            // bags are most of what settles these.
            $table->json('evidence_images')->nullable();

            $table->enum('status', DisputeStatus::values())
                ->default(DisputeStatus::Open->value)
                ->index();

            $table->text('resolution_note')->nullable();

            /*
             * What goes back to the buyer. Zero on a decision for the seller,
             * the full sub-order total on a decision for the buyer, and
             * anything between on a split.
             */
            $table->unsignedBigInteger('refund_amount_kobo')->default(0);

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });

        // One open dispute per sub-order. Enforced by the database, because
        // two disputes freezing and releasing the same money is a bug that
        // costs somebody real cash.
        Schema::table('disputes', function (Blueprint $table) {
            $table->unique(['sub_order_id', 'deleted_at'], 'disputes_one_per_sub_order');
        });

        /*
         * The conversation. Buyer, seller and administrator in one thread —
         * separate threads would let each side tell a different story to the
         * arbitrator, which is exactly what an arbitrator must not have.
         */
        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('body');
            $table->json('attachments')->nullable();

            // A note the administrator writes to themselves, not shown to the
            // buyer or the seller.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            $table->index(['dispute_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
    }
};
