<?php

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\UnitOfMeasure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('seller_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');

            $table->enum('condition', ProductCondition::values())->default(ProductCondition::New->value);
            $table->enum('unit_of_measure', UnitOfMeasure::values())->default(UnitOfMeasure::Piece->value);

            /*
             * Money is stored in kobo as an integer. Naira prices run to seven
             * figures for a tonne of feed or a generator, so a float would start
             * losing kobo exactly where it matters.
             */
            $table->unsignedBigInteger('price_kobo');
            $table->unsignedBigInteger('compare_at_price_kobo')->nullable();

            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('min_order_quantity')->default(1);

            $table->boolean('is_negotiable')->default(false);
            $table->boolean('requires_delivery_quote')->default(false);

            // Live birds and perishables need a stated handling arrangement:
            // see the check in App\Models\Product and the seller form.
            $table->boolean('is_perishable')->default(false);
            $table->boolean('is_live_animal')->default(false);
            $table->text('handling_note')->nullable();

            $table->enum('status', ProductStatus::values())
                ->default(ProductStatus::Draft->value);

            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The catalogue's hot paths: browse a category, browse a seller,
            // sort by price or recency within whatever is publicly visible.
            $table->index(['status', 'category_id']);
            $table->index(['status', 'seller_id']);
            $table->index(['status', 'price_kobo']);
            $table->index(['status', 'published_at']);
            $table->index(['is_live_animal', 'is_perishable']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // Size or weight options: "50kg bag" vs "25kg bag", "Cockerel" vs
        // "Pullet". The delta is signed because a smaller option costs less.
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->bigInteger('price_delta_kobo')->default(0);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->string('sku', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // Buy more, pay less per unit — how feed and day-old chicks are really
        // priced in this market.
        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity');
            $table->unsignedBigInteger('unit_price_kobo');
            $table->timestamps();

            $table->unique(['product_id', 'min_quantity']);
            $table->index(['product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};
