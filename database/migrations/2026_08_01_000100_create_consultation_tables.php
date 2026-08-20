<?php

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Somebody asking the company for help.
         *
         * Not a mentor engagement: the client does not choose a person, they
         * ask the company, and the company decides who deals with it. There is
         * deliberately no professional_id here.
         *
         * Almost every column is nullable, and that is the design rather than
         * laziness. A farmer whose birds are dying should be able to submit in
         * thirty seconds with a name, a number, an email and a tier; everything
         * else is something we will ask on the phone anyway.
         */
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();

            /*
             * Nullable: a guest can book without an account, because asking
             * somebody to register while their flock is dying loses the
             * booking. It is filled in later if they register with the same
             * email — see ConsultationService::claimFor().
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Taken on the form even from a signed-in user: the person to ring
            // about this problem is not always the account holder.
            $table->string('full_name');
            $table->string('phone', 32);
            $table->string('email')->index();

            $table->enum('tier', ConsultationTier::values())
                ->default(ConsultationTier::Standard->value)
                ->index();

            // Everything from here down is optional.
            $table->string('category')->nullable();
            $table->text('situation')->nullable();
            $table->string('farm_type', 120)->nullable();
            $table->string('animal_type', 120)->nullable();
            $table->unsignedInteger('flock_size')->nullable();
            $table->string('state', 64)->nullable();
            $table->string('lga', 64)->nullable();

            // Photographs of sick birds, housing, feed. A vet asks for these
            // before anything else, so the form offers them up front.
            $table->json('attachments')->nullable();

            $table->enum('status', ConsultationStatus::values())
                ->default(ConsultationStatus::Submitted->value)
                ->index();

            /*
             * There is no fixed price. The amount is whatever the company
             * agreed on the phone, entered afterwards, which is why it is
             * nullable and why quoting is its own recorded act.
             */
            $table->unsignedBigInteger('quoted_amount_kobo')->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->text('quote_note')->nullable();
            $table->foreignId('quoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at')->nullable();

            // Payment runs through the ordinary order and webhook path.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_reference', 40)->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->text('admin_notes')->nullable();

            /*
             * The promise, computed at submission from the tier and the
             * settings that were in force then. Stored rather than derived, so
             * changing the standard window next month does not retroactively
             * make last week's bookings late — or, worse, on time.
             */
            $table->timestamp('response_due_at')->nullable()->index();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // The shape the admin queue is read in: overdue first, then soonest.
            $table->index(['status', 'response_due_at']);
            $table->index(['user_id', 'created_at']);
        });

        /*
         * What the client actually bought: the write-up.
         *
         * Draft until `published_at` is set. An administrator working through a
         * report over two days must not have half of it appear in somebody's
         * dashboard, so nothing is visible to the client until it is
         * deliberately published.
         */
        Schema::create('consultation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->longText('findings');
            $table->longText('recommendations');
            $table->longText('follow_up_actions')->nullable();

            $table->json('attachments')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->index();

            $table->timestamps();

            $table->index(['consultation_id', 'published_at']);
        });

        /*
         * The conversation afterwards.
         *
         * A consultation that ends the moment the report lands is worth less
         * than one you can come back to when the advice meets the farm. Open
         * for `consultation_followup_days` after completion, and both sides
         * write into the same thread.
         */
        Schema::create('consultation_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();

            // Null for a guest who has no account but does have the reference.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Who is speaking, kept explicitly rather than inferred from the
            // role: an administrator can also be a client somewhere else.
            $table->string('sender', 16);

            $table->text('message');
            $table->json('attachments')->nullable();

            // A note the company writes to itself.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            $table->index(['consultation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_followups');
        Schema::dropIfExists('consultation_reports');
        Schema::dropIfExists('consultations');
    }
};
