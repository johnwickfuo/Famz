<?php

namespace App\Http\Controllers;

use App\Content\LegalCopy;
use App\Content\PlatformCopy;
use App\Services\Branding\BrandingService;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages that explain what this place is.
 *
 * Copy lives in App\Content as structured PHP rather than in the Vue
 * components, for one reason that matters: every mention of the company is a
 * `{company}` placeholder, and it is expanded here, on the server, from
 * BrandingService. A rename in settings renames the platform in its own terms
 * of service on the very next request.
 *
 * Expansion walks the whole structure rather than a known list of fields.
 * Copy grows — a section gains a note, a service gains a caption — and an
 * expander that only knew about `body` would silently start shipping a literal
 * "{company}" to somebody's screen the first time that happened.
 */
class PageController extends Controller
{
    /**
     * Content is identical for everybody and changes only when branding or a
     * platform setting does, both of which flush this.
     */
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PlatformCopy $copy,
        private readonly LegalCopy $legal,
        private readonly BrandingService $branding,
    ) {}

    public function about(): Response
    {
        return Inertia::render('Pages/About', [
            'page' => $this->expanded('about', fn (): array => $this->copy->about()),
        ]);
    }

    public function howItWorks(): Response
    {
        return Inertia::render('Pages/HowItWorks', [
            'page' => $this->expanded('how-it-works', fn (): array => $this->copy->howItWorks()),
        ]);
    }

    public function faq(): Response
    {
        return Inertia::render('Pages/Faq', [
            'page' => $this->expanded('faq', fn (): array => $this->copy->faq()),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('Pages/Legal', [
            'page' => $this->expanded('terms', fn (): array => $this->legal->terms()),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('Pages/Legal', [
            'page' => $this->expanded('privacy', fn (): array => $this->legal->privacy()),
        ]);
    }

    /**
     * The seller, mentor and worker guides.
     */
    public function guide(string $role): Response
    {
        $guides = $this->copy->guides();

        abort_unless(isset($guides[$role]), 404);

        return Inertia::render('Pages/Guide', [
            'page' => $this->expanded("guide:{$role}", fn (): array => $guides[$role]),
            'role' => $role,
            'others' => collect($guides)
                ->except($role)
                ->map(fn (array $guide, string $key): array => [
                    'role' => $key,
                    'title' => $this->branding->replacePlaceholders($guide['title']),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function expanded(string $key, callable $build): array
    {
        return Cache::remember(
            $this->cacheKey($key),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->walk($build()),
        );
    }

    /**
     * Expand placeholders through an arbitrarily nested structure.
     *
     * @param  array<mixed>  $content
     * @return array<mixed>
     */
    private function walk(array $content): array
    {
        return array_map(function (mixed $value): mixed {
            if (is_array($value)) {
                return $this->walk($value);
            }

            return is_string($value)
                ? $this->branding->replacePlaceholders($value)
                : $value;
        }, $content);
    }

    /**
     * Keyed on the branding cache key as well as the page.
     *
     * Without that, renaming the company would leave every one of these pages
     * serving the old name for an hour — the exact failure the placeholder
     * system exists to prevent, reintroduced by the cache in front of it.
     */
    private function cacheKey(string $page): string
    {
        return 'page-copy:'.$page.':'.md5(serialize($this->branding->placeholders()));
    }
}
