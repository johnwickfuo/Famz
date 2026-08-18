<?php

use App\Services\Branding\BrandingKey;

return [

    /*
    |--------------------------------------------------------------------------
    | Branding asset disk
    |--------------------------------------------------------------------------
    |
    | Where the logo, dark logo and favicon uploaded from the admin panel are
    | stored. Must be a publicly readable disk.
    |
    */

    'disk' => env('BRANDING_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Upload directory
    |--------------------------------------------------------------------------
    */

    'directory' => env('BRANDING_DIRECTORY', 'branding'),

    /*
    |--------------------------------------------------------------------------
    | Settings keys
    |--------------------------------------------------------------------------
    |
    | Listed here for reference only. The authoritative list is the
    | App\Services\Branding\BrandingKey enum — nothing reads these strings.
    |
    */

    'keys' => BrandingKey::values(),

];
