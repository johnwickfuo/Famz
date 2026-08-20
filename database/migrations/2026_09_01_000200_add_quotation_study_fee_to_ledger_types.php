<?php

use App\Enums\LedgerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the ledger's type column for quotation study fees.
     *
     * The column is an enum, so the set of allowed values lives in the schema
     * as well as in PHP; adding a case to the one without the other produces a
     * write that fails at the database rather than in code.
     */
    public function up(): void
    {
        $this->setTypeEnum(LedgerType::values());
    }

    public function down(): void
    {
        $this->setTypeEnum(array_values(array_filter(
            LedgerType::values(),
            fn (string $value): bool => $value !== LedgerType::QuotationStudyFee->value,
        )));
    }

    /**
     * @param  array<int, string>  $values
     */
    private function setTypeEnum(array $values): void
    {
        // SQLite has no enum type; the column is plain text and needs nothing.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $list = collect($values)->map(fn (string $v): string => "'".$v."'")->implode(', ');

        DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM({$list}) NOT NULL");
    }
};
