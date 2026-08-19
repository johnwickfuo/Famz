<?php

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\SubOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The order is the payment envelope: one payment, one buyer, one
         * gateway reference. What actually gets fulfilled lives on the
         * sub-orders, one per seller.
         */
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // What the buyer quotes when they call about the order.
            $table->string('reference', 32)->unique();

            $table->unsignedBigInteger('subtotal_kobo');
            $table->unsignedBigInteger('delivery_total_kobo')->default(0);
            $table->unsignedBigInteger('grand_total_kobo');
            $table->string('currency', 3)->default('NGN');

            $table->enum('status', OrderStatus::values())
                ->default(OrderStatus::PendingPayment->value)
                ->index();

            $table->string('payment_gateway', 32)->nullable();

            // The gateway's own identifier. Unique because it is what makes
            // webhook handling idempotent.
            $table->string('gateway_reference')->nullable()->unique();

            $table->timestamp('paid_at')->nullable();

            // Where it is going. Snapshotted rather than joined to a profile,
            // because a buyer who moves house must not rewrite their history.
            $table->string('delivery_name');
            $table->string('delivery_phone', 32);
            $table->text('delivery_address');
            $table->string('delivery_state', 64);
            $table->string('delivery_lga', 96);
            $table->text('delivery_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('sub_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('seller_profiles')->restrictOnDelete();

            $table->string('reference', 40)->unique();

            $table->unsignedBigInteger('subtotal_kobo');

            /*
             * The commission rate as it stood at purchase. Snapshotted so that
             * changing the platform's rate never rewrites what a seller was
             * promised on an order they have already fulfilled.
             */
            $table->decimal('commission_percent_snapshot', 5, 2);
            $table->unsignedBigInteger('commission_amount_kobo');
            $table->unsignedBigInteger('seller_payout_amount_kobo');

            $table->enum('delivery_method', DeliveryMethod::values())
                ->default(DeliveryMethod::SellerArranged->value);
            $table->unsignedBigInteger('delivery_fee_kobo')->default(0);

            $table->enum('status', SubOrderStatus::values())
                ->default(SubOrderStatus::Pending->value)
                ->index();

            $table->text('rejection_reason')->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('settled_at')->nullable();

            /*
             * When escrow releases on its own. Set when the seller marks the
             * order delivered; cleared if a dispute is raised.
             */
            $table->timestamp('auto_release_at')->nullable()->index();

            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->constrained()->cascadeOnDelete();

            // Kept for linking back where the product still exists, but never
            // relied on for the historical record below.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The purchase, snapshotted. A seller renaming or repricing a
             * product, or deleting it altogether, must not change what a
             * receipt from last month says.
             */
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('unit_of_measure', 24);
            $table->unsignedBigInteger('unit_price_kobo');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_kobo');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('sub_orders');
        Schema::dropIfExists('orders');
    }
};
