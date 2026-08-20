<?php

use App\Enums\DisputeReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the dispute reason column for the mentorship cases.
     *
     * The column is an enum, so the set of allowed values lives in the schema
     * as well as in PHP; adding a case to the one without the other produces a
     * write that fails at the database rather than in code.
     */
    public function up(): void
    {
        $this->setReasonEnum(DisputeReason::values());
    }

    public function down(): void
    {
        $this->setReasonEnum(array_column(DisputeReason::marketplaceCases(), 'value'));
    }

    /**
     * @param  array<int, string>  $values
     */
    private function setReasonEnum(array $values): void
    {
        // SQLite has no enum type; the column is plain text and needs nothing.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $list = collect($values)->map(fn (string $v): string => "'".$v."'")->implode(', ');

        DB::statement("ALTER TABLE disputes MODIFY COLUMN reason ENUM({$list}) NOT NULL");
    }
};
