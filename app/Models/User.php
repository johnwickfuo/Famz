<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * The profile row, created on demand so callers never have to null-check.
     */
    public function profileOrNew(): Profile
    {
        return $this->profile ?? $this->profile()->make();
    }

    public function displayName(): string
    {
        return filled($this->profile?->display_name)
            ? $this->profile->display_name
            : $this->name;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    /**
     * Roles are additive; holding one never implies another.
     */
    public function holdsRole(RoleName $role): bool
    {
        return $this->hasRole($role->value);
    }

    /**
     * Filament calls this for every panel the user tries to open. Panel access
     * is decided by the role that matches the panel id, and a suspended or
     * unverified account never gets in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isSuspended() || ! $this->hasVerifiedEmail()) {
            return false;
        }

        $role = RoleName::tryFrom($panel->getId());

        return $role !== null && $this->holdsRole($role);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->profile?->avatarUrl();
    }
}
