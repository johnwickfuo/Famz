<?php

namespace App\Services\Platform;

use App\Services\Branding\BrandingService;
use Illuminate\Support\Str;

/**
 * The meta tags a crawler and a link preview see.
 *
 * Server-rendered, in the Blade shell, rather than set by Vue after the page
 * boots. A crawler reading a JavaScript-driven title gets whatever was in the
 * HTML when it arrived, and a WhatsApp link preview — which is how most of this
 * platform's pages will actually be shared in Nigeria — does not run JavaScript
 * at all.
 *
 * Everything here is per-request state rather than a shared singleton value:
 * a controller sets what it knows, and anything it does not set falls back to
 * branding.
 */
class Seo
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $image = null;

    private string $type = 'website';

    private bool $noindex = false;

    public function __construct(private readonly BrandingService $branding) {}

    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function description(?string $description): self
    {
        // Trimmed to what a search result and a link preview actually show.
        // A 600-character description is not more information, it is the same
        // information with the end cut off by somebody else.
        $this->description = $description === null
            ? null
            : Str::limit(trim(preg_replace('/\s+/u', ' ', $description) ?? $description), 160);

        return $this;
    }

    public function image(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Keep this page out of search results.
     *
     * For pages that are reachable but should not be indexed — a private
     * checkout link, a worker profile, anything carrying a contact detail.
     */
    public function noindex(bool $noindex = true): self
    {
        $this->noindex = $noindex;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $url): array
    {
        $company = $this->branding->name();

        /*
         * The company name is appended rather than replaced. A shared link
         * reading "Broiler starter mash" tells nobody where it came from;
         * the same title followed by a separator and the platform's own name
         * does, and the separator is what stops the name reading as part of
         * the product.
         */
        $title = $this->title === null
            ? $company
            : $this->title.' · '.$company;

        return [
            'title' => $title,
            'description' => $this->description ?? $this->branding->tagline() ?? '',
            'image' => $this->image ?? $this->branding->payload()['logo_url'] ?? null,
            'url' => $url,
            'type' => $this->type,
            'site' => $company,
            'noindex' => $this->noindex,
        ];
    }
}
