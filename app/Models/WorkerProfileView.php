<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record of somebody looking at a worker.
 *
 * This table is what makes the phone number rule enforceable rather than merely
 * stated. A recruiter quietly working through every open worker in a state to
 * build a call list looks exactly like ordinary use from any single request; it
 * only looks like harvesting when the requests are counted together, and this
 * is where they are counted.
 */
class WorkerProfileView extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'contact_released' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkerProfile, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class, 'worker_profile_id');
    }

    /**
     * @return BelongsTo<EmployerProfile, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(EmployerProfile::class, 'employer_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Views that actually released a number, which is the only kind that counts
     * against the daily limit.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeReleasing(Builder $query): void
    {
        $query->where('contact_released', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSince(Builder $query, Carbon $since): void
    {
        $query->where('created_at', '>=', $since);
    }
}
