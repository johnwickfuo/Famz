<?php

use App\Enums\BillingInterval;
use App\Enums\BillingType;
use App\Enums\EngagementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A client hiring a mentor.
         *
         * Every commercial term is SNAPSHOT here rather than read back through
         * the package: a mentor is free to reprice tomorrow, and an engagement
         * agreed today has to keep the numbers it was agreed on. The same
         * reasoning as the sub-order's commission snapshot in Phase 3.
         */
        Schema::create('mentorship_engagements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('mentor_profile_id')->constrained()->restrictOnDelete();

            // Nullable so an engagement outlives the package it was bought
            // from; the snapshot below is what actually governs it.
            $table->foreignId('mentorship_package_id')->nullable()
                ->constrained('mentorship_packages')->nullOnDelete();

            $table->string('package_title');
            $table->text('package_description')->nullable();

            $table->enum('billing_type', BillingType::values());
            $table->enum('billing_interval', BillingInterval::values())->nullable();

            $table->unsignedBigInteger('price_kobo');
            $table->string('currency', 3)->default('NGN');

            // The rate as it stood the day this was agreed.
            $table->decimal('commission_percent_snapshot', 5, 2);
            $table->unsignedBigInteger('platform_amount_kobo');
            $table->unsignedBigInteger('mentor_amount_kobo');

            // What the client said they needed, carried over from the match.
            $table->text('brief')->nullable();
            $table->foreignId('mentorship_match_id')->nullable();

            $table->enum('status', EngagementStatus::values())
                ->default(EngagementStatus::PendingPayment->value)
                ->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('mentor_marked_complete_at')->nullable();
            $table->timestamp('client_confirmed_at')->nullable();

            /*
             * When silence becomes agreement. Set when the mentor marks the
             * work done; a scheduled command confirms anything past it, so a
             * mentor is never left unpaid because a client stopped reading
             * their email.
             */
            $table->timestamp('auto_confirm_at')->nullable()->index();
            $table->boolean('auto_confirmed')->default(false);

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['mentor_profile_id', 'status']);
        });

        /*
         * One row per period, and exactly one row for a one-off.
         *
         * Giving a one-time engagement an invoice too is deliberate: it means
         * there is a single path from "money arrived" through held to released,
         * instead of two that have to be kept in step. Everything the ledger
         * does for mentorship hangs off an invoice.
         */
        Schema::create('mentorship_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->foreignId('mentorship_engagement_id')->constrained()->cascadeOnDelete();

            // 1, 2, 3… so "the third month" is a fact rather than a date sum.
            $table->unsignedInteger('sequence')->default(1);

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('platform_amount_kobo');
            $table->unsignedBigInteger('mentor_amount_kobo');
            $table->string('currency', 3)->default('NGN');

            // The marketplace order that paid for it, so a mentorship payment
            // goes through the same gateway, callback and webhook as everything
            // else. Nothing about payment is special-cased for mentorship.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_reference', 40)->nullable();

            $table->enum('status', InvoiceStatus::values())
                ->default(InvoiceStatus::PendingPayment->value)
                ->index();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->unique(['mentorship_engagement_id', 'sequence'], 'engagement_invoice_sequence_unique');
        });

        /*
         * Every match run, kept for tuning.
         *
         * The point is to be able to ask later "what did we show, and did they
         * hire any of it" — which needs the request, the tags we derived, how
         * we derived them, and the ranked result, all as they were.
         */
        Schema::create('mentorship_matches', function (Blueprint $table) {
            $table->id();

            // Nullable: somebody may look before they sign in.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_token', 64)->nullable()->index();

            $table->text('need_description');
            $table->string('sector', 64)->nullable();
            $table->string('preferred_state', 64)->nullable();
            $table->boolean('wants_remote')->default(true);
            $table->boolean('wants_in_person')->default(false);
            $table->unsignedBigInteger('budget_min_kobo')->nullable();
            $table->unsignedBigInteger('budget_max_kobo')->nullable();

            // Which tags the need was mapped onto, and by what.
            $table->json('matched_specialisation_ids')->nullable();
            $table->string('resolver', 32)->default('keyword');
            $table->text('resolver_note')->nullable();

            // The shortlist as it was ranked, scores included.
            $table->json('results')->nullable();
            $table->unsignedInteger('results_count')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('mentor_reviews', function (Blueprint $table) {
            $table->id();

            // One review per engagement, enforced by the database rather than
            // by a check somebody can forget to write.
            $table->foreignId('mentorship_engagement_id')->unique()->constrained()->cascadeOnDelete();

            $table->foreignId('mentor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            $table->enum('status', ReviewStatus::values())
                ->default(ReviewStatus::Pending->value)
                ->index();

            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_note')->nullable();

            $table->timestamps();

            $table->index(['mentor_profile_id', 'status']);
        });

        /*
         * The ledger learns about mentorship.
         *
         * Marketplace money hangs off a sub-order; mentorship money hangs off
         * an invoice. Rather than bend one into the other, the ledger simply
         * carries both keys and every entry has exactly one of them set.
         */
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('mentorship_invoice_id')->nullable()->after('sub_order_id')
                ->constrained()->nullOnDelete();

            $table->index(['mentorship_invoice_id', 'type']);
        });

        /*
         * Disputes learn about mentorship too.
         *
         * A dispute is a dispute: same thread, same statuses, same admin queue,
         * same conservation law. Only the subject changes, so sub_order_id
         * becomes nullable and an engagement can take its place.
         */
        Schema::table('disputes', function (Blueprint $table) {
            $table->foreignId('mentorship_engagement_id')->nullable()->after('sub_order_id')
                ->constrained()->cascadeOnDelete();
        });

        // The foreign key has to go before the column can change type, and
        // come back after.
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropForeign(['sub_order_id']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->foreignId('sub_order_id')->nullable()->change();
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->foreign('sub_order_id')->references('id')->on('sub_orders')->cascadeOnDelete();

            /*
             * The mentorship half of the "one live dispute per subject" rule.
             * MySQL counts NULLs as distinct in a unique index, so the existing
             * sub-order constraint is untouched by engagement disputes and this
             * one is untouched by marketplace disputes.
             */
            $table->unique(['mentorship_engagement_id', 'deleted_at'], 'disputes_one_per_engagement');
        });

        // Deferred until mentorship_matches exists.
        Schema::table('mentorship_engagements', function (Blueprint $table) {
            $table->foreign('mentorship_match_id')->references('id')->on('mentorship_matches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mentorship_engagements', function (Blueprint $table) {
            $table->dropForeign(['mentorship_match_id']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropUnique('disputes_one_per_engagement');
            $table->dropConstrainedForeignId('mentorship_engagement_id');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['mentorship_invoice_id', 'type']);
            $table->dropConstrainedForeignId('mentorship_invoice_id');
        });

        Schema::dropIfExists('mentor_reviews');
        Schema::dropIfExists('mentorship_matches');
        Schema::dropIfExists('mentorship_invoices');
        Schema::dropIfExists('mentorship_engagements');
    }
};
