<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A seller's application and, once approved, their trading identity.
 *
 * The application and the storefront are the same record on purpose: an
 * administrator reviewing an application is looking at exactly what a shopper
 * will later see.
 */
#[Fillable([
    'business_name',
    'cac_number',
    'business_type',
    'address',
    'state',
    'lga',
    'phone',
    'whatsapp',
    'id_document',
    'logo',
    'description',

    // Set by an administrator from the seller screen, never by the applicant:
    // the public application path fills from an explicit allowlist in
    // SellerApplicationRequest, so this cannot be reached from there.
    'auto_approve_products',
])]
class SellerProfile extends Model
{
    use HasFactory;

    /**
     * The number of approved listings after which a seller's new products stop
     * queueing for review. Three is enough to show they understand what a
     * listing should look like, and short enough not to punish a real trader.
     */
    public const AUTO_APPROVE_THRESHOLD = 3;

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => SellerStatus::class,
            'auto_approve_products' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => SellerStatus::Pending->value,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $seller): void {
            if (blank($seller->slug)) {
                $seller->slug = static::uniqueSlug($seller->business_name);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'seller';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    /**
     * What this seller charges to deliver to each state.
     *
     * @return HasMany<SellerDeliveryRate, $this>
     */
    public function deliveryRates(): HasMany
    {
        return $this->hasMany(SellerDeliveryRate::class, 'seller_id');
    }

    /**
     * @return HasMany<SubOrder, $this>
     */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class, 'seller_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', SellerStatus::Approved);
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [SellerStatus::Pending, SellerStatus::NeedsMoreInfo]);
    }

    public function isApproved(): bool
    {
        return $this->status === SellerStatus::Approved;
    }

    public function canSell(): bool
    {
        return $this->status->canSell();
    }

    public function approvedProductCount(): int
    {
        return $this->products()
            ->whereIn('status', [ProductStatus::Active, ProductStatus::OutOfStock])
            ->count();
    }

    /**
     * Whether this seller's next listing goes live without review.
     *
     * An administrator's explicit decision always wins; otherwise the seller
     * earns it by getting three listings approved.
     */
    public function skipsProductReview(): bool
    {
        if ($this->auto_approve_products !== null) {
            return $this->auto_approve_products;
        }

        return $this->approvedProductCount() >= self::AUTO_APPROVE_THRESHOLD;
    }

    /**
     * The status a newly submitted listing from this seller should take.
     */
    public function statusForNewListing(): ProductStatus
    {
        return $this->skipsProductReview()
            ? ProductStatus::Active
            : ProductStatus::PendingReview;
    }

    public function logoUrl(): ?string
    {
        return $this->fileUrl($this->logo);
    }

    public function idDocumentUrl(): ?string
    {
        return $this->fileUrl($this->id_document);
    }

    private function fileUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk(config('filesystems.default'))->url($path);
    }

    public function location(): string
    {
        return collect([$this->lga, $this->state])->filter()->implode(', ');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
