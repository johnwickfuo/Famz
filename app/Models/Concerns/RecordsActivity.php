<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An audit trail on the models where somebody will one day ask "who did this".
 *
 * Applied to the sensitive ones only — money, moderation decisions, roles,
 * payout destinations — rather than to everything. A log of every product view
 * would be a table nobody can search and a disk nobody budgeted for, and it
 * would bury the twelve rows that actually matter.
 *
 * Three settings do the work here:
 *
 * `logOnlyDirty` records what changed rather than the whole row, so reading the
 * log tells you what happened rather than what the record looked like.
 *
 * `dontLogEmptyChanges` drops a save that changed nothing. Without it, every
 * touch of a model writes a row saying nothing changed, which is most of them.
 *
 * `logExcept` keeps secrets out. A log is a copy of the data, and a copy of a
 * bank account number in a table nobody thinks of as sensitive is how a leak
 * happens through the back door.
 */
trait RecordsActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->activityAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->useLogName($this->activityLogName())
            ->setDescriptionForEvent(fn (string $event): string => $event);
    }

    /**
     * What is worth recording on this model.
     *
     * Overridden per model. The default is deliberately narrow rather than
     * `['*']`: a wildcard picks up whatever column gets added next, including
     * the sensitive one somebody adds in six months without thinking about
     * this file.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['status'];
    }

    protected function activityLogName(): string
    {
        return class_basename($this);
    }
}
