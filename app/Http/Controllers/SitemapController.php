<?php

namespace App\Http\Controllers;

use App\Enums\SellerStatus;
use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Course;
use App\Models\JobListing;
use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The sitemap, and what is deliberately missing from it.
 *
 * The rule that shapes this file: NOTHING THAT CARRIES A CONTACT DETAIL GOES IN
 * A SITEMAP. A sitemap is an invitation to index, and indexing a page with
 * somebody's phone number on it puts that number in a search engine forever,
 * outside every rate limit and log the platform built to protect it.
 *
 * So the worker directory is absent. So are mentor profiles, which carry a
 * contact detail once an engagement is paid for and which have no business
 * being crawled either way. So is anything behind a signed or private link.
 *
 * What IS here is the public catalogue — products, categories, storefronts,
 * courses, job listings, buying requests — and the content pages. Those are
 * things the platform actively wants found.
 */
class SitemapController extends Controller
{
    /**
     * Rebuilt hourly. A crawler asking more often than that gets the cached
     * copy, which is the correct answer: the sitemap is a hint, not a feed.
     */
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Sitemaps have a hard limit of 50,000 URLs, and a practical limit far
     * below it — a 40MB XML document nobody can parse helps no one.
     */
    private const PER_TYPE = 5000;

    public function __invoke(): Response
    {
        $xml = Cache::remember('sitemap:xml', self::CACHE_TTL_SECONDS, fn (): string => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(): string
    {
        $urls = [
            ...$this->staticPages(),
            ...$this->categories(),
            ...$this->products(),
            ...$this->storefronts(),
            ...$this->courses(),
            ...$this->jobs(),
            ...$this->buyerRequests(),
        ];

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1).'</loc>';

            if (isset($url['lastmod'])) {
                $lines[] = '    <lastmod>'.$url['lastmod'].'</lastmod>';
            }

            $lines[] = '    <changefreq>'.$url['changefreq'].'</changefreq>';
            $lines[] = '    <priority>'.$url['priority'].'</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function staticPages(): array
    {
        return collect([
            ['home', '1.0', 'daily'],
            ['catalogue.home', '0.9', 'daily'],
            ['academy.home', '0.8', 'weekly'],
            ['academy.catalogue', '0.8', 'weekly'],
            ['mentors.find', '0.7', 'weekly'],
            ['consultations.create', '0.7', 'monthly'],
            ['quotations.create', '0.7', 'monthly'],
            ['jobs.index', '0.8', 'daily'],
            ['requests.index', '0.7', 'daily'],
            ['assistant.show', '0.8', 'monthly'],
            ['pages.about', '0.5', 'monthly'],
            ['pages.how-it-works', '0.6', 'monthly'],
            ['pages.faq', '0.6', 'monthly'],
            ['pages.terms', '0.3', 'yearly'],
            ['pages.privacy', '0.3', 'yearly'],
            ['contact.create', '0.5', 'monthly'],
        ])
            ->map(fn (array $page): array => [
                'loc' => route($page[0]),
                'priority' => $page[1],
                'changefreq' => $page[2],
            ])
            ->concat(collect(['seller', 'mentor', 'worker'])->map(fn (string $role): array => [
                'loc' => route('pages.guide', $role),
                'priority' => '0.5',
                'changefreq' => 'monthly',
            ]))
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function categories(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Category $category): array => [
                'loc' => route('catalogue.category', $category),
                'priority' => '0.7',
                'changefreq' => 'weekly',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function products(): array
    {
        return Product::query()
            ->visible()
            ->latest('updated_at')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Product $product): array => [
                'loc' => route('catalogue.product', $product),
                'lastmod' => $product->updated_at?->toAtomString() ?? now()->toAtomString(),
                'priority' => '0.6',
                'changefreq' => 'weekly',
            ])
            ->all();
    }

    /**
     * Seller storefronts, which name a business rather than a person.
     *
     * A business trading publicly wants to be found; that is the difference
     * between this and the worker directory, which is a list of individuals
     * who did not ask to be indexed.
     *
     * @return array<int, array<string, string>>
     */
    private function storefronts(): array
    {
        return SellerProfile::query()
            ->where('status', SellerStatus::Approved)
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (SellerProfile $seller): array => [
                'loc' => route('catalogue.storefront', $seller),
                'priority' => '0.5',
                'changefreq' => 'weekly',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function courses(): array
    {
        return Course::query()
            ->published()
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Course $course): array => [
                'loc' => route('academy.course', $course),
                'lastmod' => $course->updated_at?->toAtomString() ?? now()->toAtomString(),
                'priority' => '0.7',
                'changefreq' => 'monthly',
            ])
            ->all();
    }

    /**
     * Job listings — farms advertising, not workers.
     *
     * @return array<int, array<string, string>>
     */
    private function jobs(): array
    {
        return JobListing::query()
            ->onBoard()
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (JobListing $listing): array => [
                'loc' => route('jobs.show', $listing),
                'lastmod' => $listing->updated_at?->toAtomString() ?? now()->toAtomString(),
                'priority' => '0.6',
                'changefreq' => 'daily',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buyerRequests(): array
    {
        return BuyerRequest::query()
            ->publiclyVisible()
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (BuyerRequest $request): array => [
                'loc' => route('requests.show', $request),
                'priority' => '0.4',
                'changefreq' => 'daily',
            ])
            ->all();
    }
}
