<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\SubOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The payment envelope: one buyer, one payment, one gateway reference.
 *
 * What gets fulfilled lives on the sub-orders, one per seller in the cart.
 */
class Order extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_kobo' => 'integer',
            'delivery_total_kobo' => 'integer',
            'grand_total_kobo' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => OrderStatus::PendingPayment->value,
        'currency' => 'NGN',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->reference ??= static::newReference();
        });
    }

    /**
     * Short enough to read down a phone line, random enough not to be
     * guessable, and prefixed so it is obvious what it refers to.
     */
    public static function newReference(): string
    {
        do {
            $reference = 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SubOrder, $this>
     */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class);
    }

    /**
     * @return HasManyThrough<OrderItem, SubOrder, $this>
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, SubOrder::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->whereNotNull('paid_at');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /**
     * The address, formatted the way somebody would write it on a parcel.
     */
    public function deliveryAddressLines(): array
    {
        return array_values(array_filter([
            $this->delivery_name,
            $this->delivery_address,
            trim("{$this->delivery_lga}, {$this->delivery_state}"),
            $this->delivery_phone,
        ]));
    }

    /**
     * Recompute the envelope's status from the state of its parts.
     *
     * Called after any sub-order moves, so the buyer's order list says
     * something true without a background job having to catch up.
     */
    public function syncStatusFromSubOrders(): void
    {
        if (! $this->isPaid()) {
            return;
        }

        /** @var Collection<int, SubOrderStatus> $statuses */
        $statuses = $this->subOrders()->get(['status'])->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $unfulfilled = [SubOrderStatus::Refunded, SubOrderStatus::Rejected, SubOrderStatus::Cancelled];

        $status = match (true) {
            // Nobody delivered anything: the buyer gets everything back.
            $statuses->every(fn (SubOrderStatus $s): bool => in_array($s, $unfulfilled, true)) => OrderStatus::Refunded,

            // Every part has reached an end state, at least one of them well.
            $statuses->every(fn (SubOrderStatus $s): bool => $s->isFinished()) => OrderStatus::Completed,

            // Some parts are done and others are still moving.
            $statuses->contains(fn (SubOrderStatus $s): bool => in_array($s, [
                SubOrderStatus::Settled,
                SubOrderStatus::Delivered,
                ...$unfulfilled,
            ], true)) => OrderStatus::PartiallyFulfilled,

            default => OrderStatus::Paid,
        };

        if ($this->status !== $status) {
            $this->forceFill(['status' => $status])->save();
        }
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}
