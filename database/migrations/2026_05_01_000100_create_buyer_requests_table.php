<?php

use App\Enums\BuyerRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A wanted ad: somebody saying what they need rather than browsing for
         * it. The other half of a marketplace, and the half that works when a
         * buyer has no idea who stocks what.
         *
         * Reviewed before it goes public, because an unmoderated board fills up
         * with phone numbers and scams faster than anything else on a site.
         */
        Schema::create('buyer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40)->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');

            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->string('unit', 32);

            // Kobo, like every other amount here. A buyer may say "at least
            // this much" without naming a ceiling, so only the floor is
            // required.
            $table->unsignedBigInteger('budget_min_kobo')->nullable();
            $table->unsignedBigInteger('budget_max_kobo')->nullable();

            $table->string('delivery_state', 64);
            $table->string('delivery_lga', 96);

            $table->date('needed_by')->nullable();

            /*
             * Whether several sellers can share the job. A buyer wanting 500
             * bags may be glad of two sellers with 250 each; a buyer wanting
             * one tractor is not.
             */
            $table->boolean('accepts_partial_fulfilment')->default(false);

            $table->json('images')->nullable();

            $table->enum('status', BuyerRequestStatus::values())
                ->default(BuyerRequestStatus::PendingApproval->value)
                ->index();

            $table->text('rejection_reason')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Set when it is approved, not when it is submitted: the clock a
            // buyer is promised starts when their ad actually goes up.
            $table->timestamp('expires_at')->nullable()->index();

            // So the "expiring in three days" warning is sent once, not on
            // every run of the scheduler.
            $table->timestamp('expiry_warned_at')->nullable();

            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The shapes the board browses in.
            $table->index(['status', 'category_id']);
            $table->index(['status', 'delivery_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_requests');
    }
};
