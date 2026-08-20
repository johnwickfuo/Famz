<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Published feeding tables, week by week.
         *
         * This table is the reason the assistant can answer "how much feed for
         * 500 Ross 308 to week six" without inventing anything. The model is
         * forbidden from recalling figures; it is handed these, and it says
         * where they came from.
         *
         * Editable by the admin because breeders revise their tables, and
         * because what a bird actually eats in Oyo is not always what a
         * European management guide says.
         */
        Schema::create('breed_standards', function (Blueprint $table) {
            $table->id();

            $table->string('species', 40)->index();
            $table->string('breed', 80)->index();

            // Broiler, layer, dual purpose. The same breed name can appear
            // under more than one, and the figures differ completely.
            $table->string('production_type', 40)->index();

            $table->unsignedTinyInteger('week_number');

            /*
             * Grams per bird per day, and the running total in kilogrammes for
             * the whole flock-week. Both stored: the daily figure is what a
             * farmer measures out, the cumulative is what they buy.
             */
            $table->decimal('avg_feed_g_per_bird_per_day', 8, 2);
            $table->decimal('cumulative_feed_kg', 10, 4)->nullable();

            $table->unsignedInteger('target_weight_g')->nullable();

            /*
             * Water as a multiple of feed rather than an absolute. Birds drink
             * roughly twice what they eat, more in heat — and in this climate
             * the multiplier is the number that actually varies.
             */
            $table->decimal('water_multiplier', 4, 2)->default(2.0);

            $table->text('notes')->nullable();

            // What this row came from, so the assistant can cite it and an
            // administrator can tell a breeder table from a local correction.
            $table->string('source', 160)->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->unique(
                ['species', 'breed', 'production_type', 'week_number'],
                'breed_standard_week_unique',
            );
        });

        /*
         * The same idea for everything that is not poultry.
         *
         * Kept apart because the shape of the question is different: nobody
         * asks what a goat eats in week nineteen. They ask what it eats a day
         * at a given weight, which is a different table.
         */
        Schema::create('livestock_standards', function (Blueprint $table) {
            $table->id();

            $table->string('species', 40)->index();
            $table->string('category', 80);

            $table->unsignedInteger('typical_weight_kg')->nullable();

            $table->decimal('feed_kg_per_day', 8, 3)->nullable();

            // Feed as a percentage of body weight, which is how ruminant and
            // fish rations are actually quoted.
            $table->decimal('feed_percent_of_bodyweight', 5, 2)->nullable();

            $table->decimal('water_litres_per_day', 8, 2)->nullable();

            $table->text('notes')->nullable();
            $table->string('source', 160)->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->unique(['species', 'category'], 'livestock_standard_unique');
        });

        /*
         * What things cost on this platform's own marketplace.
         *
         * Deliberately not scraped from anywhere. A third-party price is stale
         * the day after it is taken and unverifiable forever; these come from
         * listings on this site, so the assistant can say where the figure came
         * from, how many sellers it is based on, and when it was captured — and
         * the answer gets better as the marketplace grows rather than rotting.
         */
        Schema::create('market_price_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            /*
             * The normalised thing being priced — "broiler starter feed",
             * "day old chick". Products are named a hundred ways; this is the
             * bucket they were sorted into.
             */
            $table->string('keyword_group', 120)->index();

            // Null means the national figure, which is what a thin state
            // sample widens to.
            $table->string('state', 64)->nullable()->index();

            $table->unsignedBigInteger('median_price_kobo');
            $table->unsignedBigInteger('min_price_kobo');
            $table->unsignedBigInteger('max_price_kobo');

            /*
             * How many listings this is based on. The single most important
             * column here: below a threshold the assistant must widen or say
             * the data is thin rather than quote a confident-sounding median
             * built from two listings.
             */
            $table->unsignedInteger('sample_size');

            $table->string('unit', 40)->nullable();
            $table->string('currency', 3)->default('NGN');

            $table->timestamp('captured_at')->index();

            $table->timestamps();

            // How a lookup reads: this thing, in this state, most recent first.
            $table->index(['keyword_group', 'state', 'captured_at']);
        });

        /*
         * A conversation, which survives the tab being closed.
         *
         * Guests get one too, keyed by a session token, because most people
         * asking a first question have not registered — and losing the thread
         * when they do register would be the platform punishing them for
         * signing up.
         */
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('session_token', 64)->nullable()->index();

            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();

            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();

            $table->enum('role', ['user', 'assistant', 'system']);
            $table->longText('content');

            /*
             * The figures that were handed to the model for this answer, kept
             * verbatim. This is the audit trail for the rule that matters: if
             * somebody says the assistant made a number up, this row says
             * exactly what it was given and where each figure came from.
             */
            $table->json('context_used')->nullable();

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            // Whether this answer came out of the cache, for the hit-rate
            // figure on the admin dashboard.
            $table->boolean('from_cache')->default(false);

            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();

            // The normalised form of the question, which is both the cache key
            // and how "top questions" is grouped.
            $table->string('normalised_question', 255)->nullable()->index();

            $table->timestamps();

            $table->index(['chat_conversation_id', 'id']);
            $table->index(['created_at', 'tokens_used']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('market_price_snapshots');
        Schema::dropIfExists('livestock_standards');
        Schema::dropIfExists('breed_standards');
    }
};
