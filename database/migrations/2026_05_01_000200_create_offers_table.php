<?php

use App\Enums\OfferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One offer engine, two features.
         *
         * A buyer haggling over a listing and a seller answering a wanted ad
         * are the same transaction seen from opposite ends: somebody proposes
         * a price and a quantity, somebody else says yes, no, or how about
         * this. Building that twice would mean two sets of expiry rules, two
         * sets of acceptance rules, and eventually two different answers to
         * "what did we actually agree".
         *
         * A counter-offer is a NEW row pointing at the one it answers. The old
         * row is marked `countered` and never rewritten, so the whole haggle
         * reads back as a chain: what was asked, what was said back, and what
         * was finally agreed.
         */
        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            // Product, or BuyerRequest.
            $table->morphs('offerable');

            $table->foreignId('initiator_id')->constrained('users')->cascadeOnDelete();

            // Who has to answer. Denormalised from the offerable on purpose:
            // "everything waiting on me" is the query both panels run most,
            // and it should not need a join to a polymorphic parent.
            $table->foreignId('responder_id')->constrained('users')->cascadeOnDelete();

            // The seller's side of the deal, where there is one. A product
            // offer has the listing's seller; a request offer has whoever is
            // offering.
            $table->foreignId('seller_id')->nullable()->constrained('seller_profiles')->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_kobo');
            $table->unsignedBigInteger('total_price_kobo');

            $table->text('message')->nullable();

            // What a seller answering a wanted ad promises. Meaningless on a
            // product offer, where the listing already says.
            $table->unsignedSmallInteger('delivery_days')->nullable();

            $table->enum('status', OfferStatus::values())
                ->default(OfferStatus::Pending->value)
                ->index();

            // The offer this one answers. Null on the first of a chain.
            $table->foreignId('parent_offer_id')->nullable()
                ->constrained('offers')->nullOnDelete();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index(['offerable_type', 'offerable_id', 'status']);
            $table->index(['responder_id', 'status']);
            $table->index(['initiator_id', 'status']);
        });

        /*
         * What an accepted offer entitles somebody to buy, and until when.
         *
         * Kept beside the offer rather than on it because it is a different
         * thing with a different life: the offer is a record of what was
         * agreed, this is a claim on stock that expires whether or not
         * anybody uses it.
         */
        Schema::create('negotiated_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('token', 64)->unique();

            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_request_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_kobo');

            // Stock held back for this buyer while the link is live. Released
            // when it expires or is used, never left dangling.
            $table->unsignedInteger('reserved_quantity')->default(0);

            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['buyer_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negotiated_purchases');
        Schema::dropIfExists('offers');
    }
};
