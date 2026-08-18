<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-text search over the two fields a shopper actually types against.
 *
 * Kept in its own migration because FULLTEXT is MySQL-specific: SQLite, which
 * the test suite runs on, has no equivalent and simply skips it. The search
 * itself degrades to a LIKE scan there — see App\Models\Product::scopeSearch().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsFullText()) {
            return;
        }

        Schema::table('products', function ($table) {
            $table->fullText(['name', 'description'], 'products_search_fulltext');
        });
    }

    public function down(): void
    {
        if (! $this->supportsFullText()) {
            return;
        }

        Schema::table('products', function ($table) {
            $table->dropFullText('products_search_fulltext');
        });
    }

    private function supportsFullText(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
