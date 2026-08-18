<?php

namespace App\Listeners;

use App\Events\SettingsChanged;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;

/**
 * Keeps the branding cache honest. Renaming the company or swapping the logo
 * has to be visible on the next request — across the site, emails, PDFs and
 * certificates — without a deploy or a manual cache clear.
 */
class FlushBrandingCache
{
    public function __construct(private readonly BrandingService $branding)
    {
    }

    public function handle(SettingsChanged $event): void
    {
        if ($event->touchesAny(BrandingKey::values())) {
            $this->branding->flush();
        }
    }
}
