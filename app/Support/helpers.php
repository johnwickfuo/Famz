<?php

use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;

if (! function_exists('branding')) {
    /**
     * The platform's identity. The only way any part of the app is allowed to
     * learn the company name.
     */
    function branding(): BrandingService
    {
        return app(BrandingService::class);
    }
}

if (! function_exists('branded')) {
    /**
     * Expand {company} / {company_short} and friends inside admin-authored
     * content so stored copy survives a rename.
     */
    function branded(?string $content): ?string
    {
        return app(BrandingService::class)->replacePlaceholders($content);
    }
}

if (! function_exists('settings')) {
    /**
     * Read a platform setting, or get the service itself when called with no
     * arguments.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $service = app(SettingsService::class);

        return $key === null ? $service : $service->get($key, $default);
    }
}
