<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Stock promised to somebody but not yet paid for.
         *
         * A separate counter rather than a decrement of `stock_quantity`,
         * because the two mean different things: `stock_quantity` is what the
         * seller has in the shed, and this is how much of it is spoken for. A
         * decrement would make a reservation indistinguishable from a sale,
         * and a seller looking at their own listing would think goods had
         * walked out of the door.
         */
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('reserved_quantity')->default(0)->after('stock_quantity');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('reserved_quantity')->default(0)->after('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });
    }
};
