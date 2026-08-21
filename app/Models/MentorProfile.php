<?php

namespace App\Models;

use App\Enums\ContactMethod;
use App\Enums\EngagementStatus;
use App\Enums\MentorStatus;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A mentor.
 *
 * The one rule this class exists to enforce: **contact details never leave here
 * without an engagement to justify them.** `publicCard()` is what every
 * listing, shortlist and profile page is built from, and it does not contain
 * the contact value at all — not hidden behind a flag the front end might
 * ignore, simply absent from the array. `contactFor()` is the only way to get
 * it, and it asks the engagement, not the caller.
 */
#[Fillable([
    'headline', 'bio', 'strengths', 'years_experience', 'qualifications', 'affiliation',
    'preferred_contact_method', 'contact_value', 'states_served', 'accepts_remote',
    'accepts_in_person', 'avatar',
])]
class MentorProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'preferred_contact_method' => ContactMethod::class,
            'status' => MentorStatus::class,
            'states_served' => 'array',
            'accepts_remote' => 'boolean',
            'accepts_in_person' => 'boolean',
            'years_experience' => 'integer',
            'average_rating' => 'float',
            'reviews_count' => 'integer',
            'engagements_completed' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $profile): void {
            $profile->status ??= MentorStatus::Pending;
        });

        static::saving(function (self $profile): void {
            $profile->slug ??= static::uniqueSlug($profile->displayName());
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'mentor';
        $slug = $base;
        $suffix = 1;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.++$suffix;
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
     * @return BelongsToMany<Specialisation, $this>
     */
    public function specialisations(): BelongsToMany
    {
        return $this->belongsToMany(Specialisation::class);
    }

    /**
     * @return HasMany<MentorshipPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(MentorshipPackage::class)->orderBy('sort_order')->orderBy('price_kobo');
    }

    /**
     * @return HasMany<MentorshipEngagement, $this>
     */
    public function engagements(): HasMany
    {
        return $this->hasMany(MentorshipEngagement::class);
    }

    /**
     * @return HasMany<MentorReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MentorReview::class);
    }

    public function displayName(): string
    {
        return $this->user?->displayName() ?? __('Mentor');
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar === null ? null : Storage::disk('public')->url($this->avatar);
    }

    public function isBookable(): bool
    {
        return $this->status->isBookable();
    }

    /**
     * What anybody may see.
     *
     * Note what is not here. There is no contact_value, no phone number and no
     * meeting link — only the METHOD, so a client can tell whether this mentor
     * works the way they need before paying to find out.
     *
     * @return array<string, mixed>
     */
    public function publicCard(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->displayName(),
            'headline' => $this->headline,
            'avatar' => $this->avatarUrl(),
            'years_experience' => $this->years_experience,
            'affiliation' => $this->affiliation,
            'rating' => $this->average_rating,
            'reviews_count' => $this->reviews_count,
            'engagements_completed' => $this->engagements_completed,
            'accepts_remote' => $this->accepts_remote,
            'accepts_in_person' => $this->accepts_in_person,
            'states_served' => $this->states_served ?? [],
            // How they like to work — not how to reach them.
            'contact_method' => $this->preferred_contact_method->label(),
            'specialisations' => $this->relationLoaded('specialisations')
                ? $this->specialisations->map(fn (Specialisation $s): array => [
                    'slug' => $s->slug,
                    'name' => $s->name,
                ])->all()
                : [],
            'url' => route('mentors.show', $this->slug),
            'from_price' => $this->cheapestPackage()?->price(),
            'from_price_kobo' => $this->cheapestPackage()?->price_kobo,
        ];
    }

    /**
     * The contact details, if and only if this engagement has earned them.
     *
     * The engagement is the argument rather than a boolean, so a caller cannot
     * pass `true` and be believed. Ownership is checked here too: an engagement
     * belonging to a different mentor unlocks nothing.
     *
     * @return array<string, string>|null
     */
    public function contactFor(?MentorshipEngagement $engagement): ?array
    {
        if ($engagement === null || $engagement->mentor_profile_id !== $this->getKey()) {
            return null;
        }

        if (! $engagement->status->revealsContact()) {
            return null;
        }

        return [
            'method' => $this->preferred_contact_method->label(),
            'method_key' => $this->preferred_contact_method->value,
            'value' => $this->contact_value,
            'action_url' => $this->preferred_contact_method->actionUrl($this->contact_value),
        ];
    }

    public function cheapestPackage(): ?MentorshipPackage
    {
        $packages = $this->relationLoaded('packages')
            ? $this->packages
            : $this->packages()->where('is_active', true)->get();

        return $packages->where('is_active', true)->sortBy('price_kobo')->first();
    }

    /**
     * Whether this mentor can work in a given state.
     *
     * An empty list means "nowhere in person", which is a real answer for
     * somebody who only does Zoom.
     */
    public function servesState(?string $state): bool
    {
        if ($state === null) {
            return true;
        }

        return collect($this->states_served ?? [])
            ->map(fn (string $s): string => mb_strtolower(trim($s)))
            ->contains(mb_strtolower(trim($state)));
    }

    /**
     * @return Collection<int, int>
     */
    public function specialisationIds(): Collection
    {
        return $this->relationLoaded('specialisations')
            ? $this->specialisations->pluck('id')
            : $this->specialisations()->pluck('specialisations.id');
    }

    /**
     * Recompute the rating and the completed count from the rows themselves.
     *
     * Never incremented. A counter that drifts from what it counts is worse
     * than no counter, and this is cheap enough to do properly.
     */
    public function refreshStandings(): void
    {
        $approved = $this->reviews()->where('status', ReviewStatus::Approved)->get();

        $this->forceFill([
            'average_rating' => $approved->isEmpty() ? null : round($approved->avg('rating'), 2),
            'reviews_count' => $approved->count(),
            'engagements_completed' => $this->engagements()
                ->where('status', EngagementStatus::Completed)
                ->count(),
        ])->save();
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('status', MentorStatus::Approved);
    }

    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        // Fails closed. A null would otherwise compile to `IS NULL` rather than
        // to a comparison matching nothing — see the note on Consultation.
        return $query->where(
            'user_id',
            $user instanceof User ? $user->getKey() : ($user ?? 0),
        );
    }
}
