<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Somebody with farm work to offer.
 *
 * The company does not check any of this and says so. The profile exists so a
 * worker can see who is asking them to travel to a village and turn up at six
 * in the morning — knowing the name of the farm is the only protection this
 * board offers, and pretending otherwise would be worse than offering nothing.
 *
 * An employer's own contact details are public by design, which is the exact
 * opposite of the worker rule and deliberately so: the asymmetry is the point.
 * Somebody advertising for staff is inviting contact; somebody looking for work
 * is not inviting a call list.
 */
#[Fillable([
    'business_name', 'business_type', 'state', 'lga', 'address', 'about',
    'contact_person', 'phone', 'email',
])]
class EmployerProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rating_average' => 'decimal:2',
            'rating_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $profile->slug ??= static::uniqueSlug($profile->business_name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'employer';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<JobListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(JobListing::class)->latest('id');
    }

    /**
     * @return HasMany<WorkerProfileView, $this>
     */
    public function workerViews(): HasMany
    {
        return $this->hasMany(WorkerProfileView::class);
    }

    public function place(): ?string
    {
        return collect([$this->lga, $this->state])->filter()->implode(', ') ?: null;
    }

    public function logoUrl(): ?string
    {
        return $this->logo === null ? null : Storage::disk('public')->url($this->logo);
    }

    public function belongsToUser(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicCard(): array
    {
        return [
            'slug' => $this->slug,
            'business_name' => $this->business_name,
            'business_type' => $this->business_type,
            'where' => $this->place(),
            'about' => $this->about,
            'logo_url' => $this->logoUrl(),
            'contact_person' => $this->contact_person,
            // Public on purpose: somebody advertising for staff is inviting
            // contact, which is not true of somebody looking for work.
            'phone' => $this->phone,
            'email' => $this->email,
            'rating_average' => $this->rating_average === null ? null : (float) $this->rating_average,
            'rating_count' => $this->rating_count,
        ];
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
