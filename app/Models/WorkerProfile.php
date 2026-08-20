<?php

namespace App\Models;

use App\Enums\PayPeriod;
use App\Enums\RoleName;
use App\Enums\WorkerAvailability;
use App\Enums\WorkTypeWanted;
use App\Support\Money;
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
 * Somebody looking for farm work.
 *
 * The phone number on this record is the most sensitive field on the platform.
 * A farm worker's number is often their only asset in a job search, and a board
 * that leaked it would be a lead-generation list for anybody willing to write a
 * scraper — so this model never exposes it by default.
 *
 * `publicCard()` has no contact field at all. `contactFor()` takes the viewer
 * and returns null unless that viewer is an authenticated employer. There is
 * deliberately no boolean parameter anywhere in this class that could be passed
 * the wrong way round, and no accessor that returns the number without being
 * asked who is looking.
 */
#[Fillable([
    'full_name', 'phone', 'whatsapp', 'state', 'lga', 'willing_to_relocate',
    'work_type_wanted', 'years_experience', 'expected_pay_min_kobo',
    'expected_pay_max_kobo', 'pay_period', 'availability', 'about',
    'is_open_to_work',
])]
class WorkerProfile extends Model
{
    use HasFactory;

    /**
     * Never serialised by accident.
     *
     * Belt and braces on top of the explicit payload builders below: if
     * somebody one day returns a WorkerProfile straight out of a controller,
     * this is what stops the number going with it.
     *
     * @var array<int, string>
     */
    protected $hidden = ['phone', 'whatsapp', 'id_document'];

    protected function casts(): array
    {
        return [
            'work_type_wanted' => WorkTypeWanted::class,
            'pay_period' => PayPeriod::class,
            'availability' => WorkerAvailability::class,
            'willing_to_relocate' => 'boolean',
            'is_open_to_work' => 'boolean',
            'is_active' => 'boolean',
            'years_experience' => 'integer',
            'expected_pay_min_kobo' => 'integer',
            'expected_pay_max_kobo' => 'integer',
            'rating_average' => 'decimal:2',
            'rating_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $profile->slug ??= static::uniqueSlug($profile->full_name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'worker';
        $slug = $base.'-'.Str::lower(Str::random(5));

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // -----------------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------------

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<WorkerSkill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(WorkerSkill::class, 'worker_profile_skill');
    }

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class)->latest('applied_at');
    }

    /**
     * @return HasMany<WorkerProfileView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(WorkerProfileView::class);
    }

    // -----------------------------------------------------------------------
    // The contact rule
    // -----------------------------------------------------------------------

    /**
     * Whether this viewer may be given the worker's number.
     *
     * One place, one rule: an authenticated user holding the employer role.
     * Not "logged in", not "has a profile" — the role, because that is what the
     * person agreed to when they registered as somebody offering work.
     *
     * A worker may always see their own.
     */
    public function contactVisibleTo(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->getKey() === $this->user_id) {
            return true;
        }

        return $viewer->holdsRole(RoleName::Employer);
    }

    /**
     * The contact details, or nothing.
     *
     * Takes the viewer rather than a boolean. A boolean parameter can be passed
     * the wrong way round by a tired person at four in the afternoon; a User
     * cannot.
     *
     * @return array{phone: string, whatsapp: string|null}|null
     */
    public function contactFor(?User $viewer): ?array
    {
        if (! $this->contactVisibleTo($viewer)) {
            return null;
        }

        return [
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
        ];
    }

    /**
     * Everything anybody may see about this worker.
     *
     * There is no contact field in here at all — not an empty one, not a null
     * one. A key that is sometimes populated is a key somebody will eventually
     * populate by mistake; a key that does not exist cannot be.
     *
     * @return array<string, mixed>
     */
    public function publicCard(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->full_name,
            'state' => $this->state,
            'lga' => $this->lga,
            'where' => $this->place(),
            'willing_to_relocate' => $this->willing_to_relocate,
            'work_type' => $this->work_type_wanted->label(),
            'years_experience' => $this->years_experience,
            'experience_label' => $this->experienceLabel(),
            'availability' => $this->availability->label(),
            'availability_tone' => $this->availability->badgeTone(),
            'pay_expectation' => $this->payExpectation(),
            'about' => $this->about,
            'photo_url' => $this->photoUrl(),
            'skills' => $this->skills->pluck('name')->all(),
            'rating_average' => $this->rating_average === null ? null : (float) $this->rating_average,
            'rating_count' => $this->rating_count,
            'is_open_to_work' => $this->is_open_to_work,
            'url' => route('jobs.workers.show', $this->slug),
        ];
    }

    // -----------------------------------------------------------------------
    // Reading it back
    // -----------------------------------------------------------------------

    public function place(): ?string
    {
        return collect([$this->lga, $this->state])->filter()->implode(', ') ?: null;
    }

    public function experienceLabel(): string
    {
        return match (true) {
            $this->years_experience < 1 => __('New to farm work'),
            $this->years_experience === 1 => __('1 year'),
            default => __(':count years', ['count' => $this->years_experience]),
        };
    }

    public function payExpectation(): ?string
    {
        $min = $this->expected_pay_min_kobo;
        $max = $this->expected_pay_max_kobo;
        $per = $this->pay_period->label();

        return match (true) {
            $min !== null && $max !== null && $min !== $max => Money::compactFromKobo($min).' – '.Money::compactFromKobo($max).' '.$per,
            $min !== null => Money::compactFromKobo($min).' '.$per,
            $max !== null => __('Up to :amount :per', ['amount' => Money::compactFromKobo($max), 'per' => $per]),
            default => null,
        };
    }

    public function photoUrl(): ?string
    {
        return $this->photo === null ? null : Storage::disk('public')->url($this->photo);
    }

    /**
     * Expected pay normalised to a month, for comparing with a listing.
     *
     * Rough by design — see PayPeriod::perMonthFactor. Used only to rank, never
     * to quote anybody a figure.
     */
    public function monthlyExpectation(): ?array
    {
        $factor = $this->pay_period->perMonthFactor();

        return [
            'min' => $this->expected_pay_min_kobo === null ? null : (int) round($this->expected_pay_min_kobo * $factor),
            'max' => $this->expected_pay_max_kobo === null ? null : (int) round($this->expected_pay_max_kobo * $factor),
        ];
    }

    public function belongsToUser(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->getKey();
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpenToWork(Builder $query): void
    {
        $query->where('is_active', true)->where('is_open_to_work', true);
    }
}
