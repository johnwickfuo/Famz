<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super administrator
    |--------------------------------------------------------------------------
    |
    | Seeded by database/seeders/SuperAdminSeeder.php. Leave the email or the
    | password empty and no account is created — an install without these is
    | safer than one with a default password.
    |
    */

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Platform Administrator'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    |
    | Filament panel id => URL path. The panel id must match a role name in
    | App\Enums\RoleName; that is what App\Http\Middleware\EnsurePanelRole and
    | User::canAccessPanel() gate on.
    |
    */

    'panels' => [
        'admin' => 'admin',
        'seller' => 'seller',
        'mentor' => 'mentor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Test email recipient
    |--------------------------------------------------------------------------
    |
    | Pre-fills the "send test email" action in the admin mail templates page.
    |
    */

    'test_email_recipient' => env('MAIL_TEST_RECIPIENT'),

];
