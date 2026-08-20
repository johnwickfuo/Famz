<?php

namespace App\Http\Controllers;

use App\Content\PlatformCopy;
use App\Services\Branding\BrandingService;
use App\Services\Platform\Seo;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public shell outside the catalogue. These sections are deliberately thin
 * — they hold a place in the navigation for parts of the platform that arrive
 * in later phases.
 */
class PublicPageController extends Controller
{
    /**
     * @var array<string, array{title: string, empty_title: string, empty_description: string}>
     */
    private const SECTIONS = [
        'jobs' => [
            'title' => 'Farm jobs',
            'empty_title' => 'No jobs posted yet',
            'empty_description' => 'Employers looking for farm workers will post here, by state and LGA.',
        ],
    ];

    public function __construct(
        private readonly PlatformCopy $copy,
        private readonly BrandingService $branding,
    ) {}

    public function home(): Response
    {
        /*
         * All eight services, from the same list the How-it-works page reads.
         *
         * The home page used to name four of them in hard-coded component
         * markup, which meant the marketplace, training, mentors and jobs were
         * discoverable and consultations, farm setup, the assistant and the
         * wanted board were not — on the one page most first-time visitors see.
         */
        /*
         * An explicit description rather than falling back to the tagline.
         * Branding ships with an empty tagline until an administrator writes
         * one, so the home page — the single most valuable page to describe —
         * was going out with no meta description at all.
         */
        app(Seo::class)->description(__(
            'Buy and sell farm inputs across Nigeria, learn from experienced farmers, get advice on your flock, find farm work, and ask free questions any time.'
        ));

        return Inertia::render('Welcome', [
            'services' => collect($this->copy->services())
                ->map(fn (array $service): array => [
                    ...$service,
                    'name' => $this->branding->replacePlaceholders($service['name']),
                    'blurb' => $this->branding->replacePlaceholders($service['blurb']),
                    'href' => route($service['route']),
                ])
                ->all(),
        ]);
    }

    public function section(string $section): Response
    {
        if (! array_key_exists($section, self::SECTIONS)) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Section', [
            'section' => self::SECTIONS[$section],
        ]);
    }
}
