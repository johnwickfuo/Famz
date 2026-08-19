<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');

            /*
             * The price as it stood when the item went into the cart. It is
             * recomputed at checkout and the buyer is told if it moved — a cart
             * that silently repriced itself between the shelf and the till is
             * how people lose trust in a marketplace.
             */
            $table->unsignedBigInteger('unit_price_kobo');

            $table->timestamps();

            // One line per product-and-option; adding again bumps the quantity.
            $table->unique(['cart_id', 'product_id', 'product_variant_id'], 'cart_items_unique_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
