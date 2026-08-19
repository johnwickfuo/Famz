<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\BillingType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a mentor sells.
 *
 * A mentor may have several at different prices — an hour on the phone, a month
 * of hand-holding, a visit to the farm — and a client picks one. Everything
 * commercial about it is copied onto the engagement at purchase, so editing a
 * package never changes what somebody already agreed to.
 */
#[Fillable([
    'title', 'description', 'billing_type', 'price_kobo', 'currency',
    'billing_interval', 'duration_description', 'sessions_included',
    'deliverables', 'is_active', 'sort_order',
])]
class MentorshipPackage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'billing_interval' => BillingInterval::class,
            'price_kobo' => 'integer',
            'sessions_included' => 'integer',
            'deliverables' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $package): void {
            // A one-off with an interval on it is a contradiction somebody
            // will eventually be billed by.
            if ($package->billing_type === BillingType::OneTime) {
                $package->billing_interval = null;
            }
        });
    }

    /**
     * @return BelongsTo<MentorProfile, $this>
     */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(MentorProfile::class, 'mentor_profile_id');
    }

    public function price(): string
    {
        return Money::fromKobo($this->price_kobo);
    }

    /**
     * The price with its period attached, because "₦40,000" and "₦40,000 a
     * month" are very different offers.
     */
    public function priceLabel(): string
    {
        if ($this->billing_type === BillingType::OneTime || $this->billing_interval === null) {
            return $this->price();
        }

        return __(':price a :unit', [
            'price' => $this->price(),
            'unit' => $this->billing_interval->unitLabel(),
        ]);
    }

    public function isPeriodic(): bool
    {
        return $this->billing_type->isPeriodic() && $this->billing_interval !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function card(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'billing_type' => $this->billing_type->value,
            'billing_label' => $this->billing_type->label(),
            'interval' => $this->billing_interval?->value,
            'price' => $this->price(),
            'price_label' => $this->priceLabel(),
            'price_kobo' => $this->price_kobo,
            'duration' => $this->duration_description,
            'sessions' => $this->sessions_included,
            'deliverables' => $this->deliverables ?? [],
            'is_periodic' => $this->isPeriodic(),
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
