<?php

use App\Enums\QuotationPowerSituation;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationWaterSource;
use App\Enums\StudyFeeCreditStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Somebody asking what it would cost to build a farm.
         *
         * Unlike a consultation, this form is long on purpose. A proposal
         * cannot be written from four fields, and the person filling this in is
         * contemplating spending millions — they are willing to spend ten
         * minutes on it. The questions here are the ones a quantity surveyor
         * would ask before pricing anything.
         */
        Schema::create('quotation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();

            /*
             * Required, unlike a consultation's. A study fee has to be paid
             * before anything happens, and paying means an account: there is no
             * such thing as a guest who can be invoiced months later.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('project_type', QuotationProjectType::values())->index();
            $table->string('farm_type', 120);

            // What they want to end up with — "20,000 birds", "5 hectares",
            // "40 tonnes a month". The unit is free text because the answer
            // differs by species and this is not worth a taxonomy.
            $table->unsignedBigInteger('target_capacity')->nullable();
            $table->string('capacity_unit', 40)->nullable();

            /*
             * Land. Whether they own it changes the proposal completely: a
             * client still shopping for a site is being quoted a design, and
             * one standing on their own land is being quoted a build.
             */
            $table->boolean('owns_land')->default(false);
            $table->decimal('land_size', 12, 2)->nullable();
            $table->string('land_unit', 24)->nullable();

            $table->string('state', 64)->nullable()->index();
            $table->string('lga', 64)->nullable();
            $table->text('address')->nullable();

            /*
             * A range, not a number. Nobody knows their budget to the naira at
             * this stage, and asking for one produces a made-up figure that
             * then anchors the whole proposal. Both ends are nullable because
             * plenty of people genuinely do not know yet.
             */
            $table->unsignedBigInteger('budget_range_min_kobo')->nullable();
            $table->unsignedBigInteger('budget_range_max_kobo')->nullable();
            $table->string('currency', 3)->default('NGN');

            $table->date('target_start_date')->nullable();

            // Which parts of the job they want. Drives the sections the
            // proposal is built around.
            $table->json('scope_wanted')->nullable();

            $table->enum('power_situation', QuotationPowerSituation::values())->nullable();
            $table->enum('water_source', QuotationWaterSource::values())->nullable();

            $table->text('additional_notes')->nullable();
            $table->json('site_photos')->nullable();

            $table->enum('status', QuotationRequestStatus::values())
                ->default(QuotationRequestStatus::Submitted->value)
                ->index();

            // Set when an administrator closes the request for reporting. There
            // is deliberately no project tracking beyond this point.
            $table->text('outcome_note')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->text('admin_notes')->nullable();

            $table->timestamps();

            // The shape the admin queue is read in.
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        /*
         * The fee that turns an enquiry into a job.
         *
         * Its own table rather than columns on the request, because the row
         * outlives the workflow: long after a request is closed, somebody will
         * ask whether this fee was ever credited against the project. That
         * question needs an answer with a name and a date on it.
         */
        Schema::create('quotation_study_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->cascadeOnDelete();

            /*
             * The amount as it was when charged, not as the setting reads now.
             * Raising the fee next quarter must not rewrite what somebody paid
             * last quarter.
             */
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3)->default('NGN');

            // Payment runs through the ordinary order and webhook path.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_reference', 64)->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();

            $table->enum('credit_status', StudyFeeCreditStatus::values())
                ->default(StudyFeeCreditStatus::Uncredited->value)
                ->index();

            // Who decided, when, and why. The whole point of this table.
            $table->foreignId('credited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('credited_at')->nullable();
            $table->text('credit_note')->nullable();

            $table->timestamps();

            $table->index(['credit_status', 'paid_at']);
        });

        /*
         * One version of a proposal.
         *
         * Never edited after it is sent. Input prices here move fast enough
         * that a document somebody is holding has to keep saying what it said,
         * so a revision is a new row and the previous one is superseded.
         */
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version')->default(1);

            $table->string('title');
            $table->longText('executive_summary')->nullable();
            $table->longText('scope_of_work')->nullable();
            $table->longText('assumptions')->nullable();
            $table->longText('exclusions')->nullable();
            $table->longText('timeline_description')->nullable();
            $table->longText('payment_terms')->nullable();

            /*
             * Totals in kobo, derived from the line items and written down.
             * Stored rather than summed on read for the same reason the whole
             * table is versioned: what was sent has to stay what was sent, even
             * if somebody later deletes a line by accident.
             */
            $table->unsignedBigInteger('subtotal_kobo')->default(0);
            $table->decimal('contingency_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('contingency_kobo')->default(0);
            $table->unsignedBigInteger('total_kobo')->default(0);
            $table->string('currency', 3)->default('NGN');

            $table->date('valid_until')->nullable()->index();

            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', QuotationStatus::values())
                ->default(QuotationStatus::Draft->value)
                ->index();

            $table->timestamp('sent_at')->nullable();

            /*
             * The company's name as it was when this went out.
             *
             * Branding is read live everywhere else, and that is right for a
             * website. It is wrong for a document somebody has already
             * received: reissuing a copy of a proposal from March must produce
             * the proposal they were sent in March, not one wearing this
             * quarter's name.
             */
            $table->string('issuer_name')->nullable();

            // The rendered PDF, kept so a reissue is the same bytes rather than
            // a re-render against whatever the templates say today.
            $table->string('pdf_path')->nullable();

            $table->timestamps();

            // One version number per request, enforced rather than hoped for.
            $table->unique(['quotation_request_id', 'version']);
            $table->index(['status', 'valid_until']);
        });

        /*
         * The priced lines, grouped into sections.
         *
         * Sections are free text rather than an enum: an administrator pricing
         * a fish farm needs headings a poultry enum would never contain, and
         * the proposal is a document rather than a data structure.
         */
        Schema::create('quotation_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();

            $table->string('section', 120)->nullable();
            $table->text('description');

            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 40)->nullable();

            $table->unsignedBigInteger('unit_price_kobo')->default(0);
            $table->unsignedBigInteger('total_kobo')->default(0);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // How the proposal is read back: section, then the admin's order.
            $table->index(['quotation_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_line_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('quotation_study_fees');
        Schema::dropIfExists('quotation_requests');
    }
};
