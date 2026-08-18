<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the first administrator from the environment. Nothing is created when
 * SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD are absent, so production installs
 * never end up with a guessable default account.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('platform.super_admin.email');
        $password = config('platform.super_admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'Skipping super admin: set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD to seed one.'
            );

            return;
        }

        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        $user->fill([
            'name' => config('platform.super_admin.name'),
            'password' => Hash::make($password),
            'status' => UserStatus::Active,
        ]);

        $user->deleted_at = null;
        $user->email_verified_at ??= now();
        $user->save();

        $user->profile()->firstOrCreate([], [
            'display_name' => $user->name,
        ]);

        $user->assignRole(RoleName::Admin->value);

        $this->command?->info("Super admin ready: {$email}");
    }
}
