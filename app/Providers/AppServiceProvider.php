<?php

namespace App\Providers;

use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\JobListing;
use App\Models\JobRating;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\WorkerProfile;
use App\Policies\BuyerRequestPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\DisputePolicy;
use App\Policies\JobListingPolicy;
use App\Policies\JobRatingPolicy;
use App\Policies\OfferPolicy;
use App\Policies\ProductPolicy;
use App\Policies\SellerProfilePolicy;
use App\Policies\SubOrderPolicy;
use App\Policies\WorkerProfilePolicy;
use App\Services\Ai\AiProvider;
use App\Services\Catalogue\CatalogueCache;
use App\Services\Platform\Seo;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\GeminiTagResolver;
use App\Services\Ai\NullAiProvider;
use App\Services\Ai\NullTagResolver;
use App\Services\Ai\TagResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The AI layer, chosen once at boot.
         *
         * With no key configured this resolves to NullTagResolver, which always
         * declines — so the keyword fallback is what runs in development, in
         * the tests, and on any deployment where nobody has signed up to
         * Google. The fallback is the path that must never break, so it is the
         * path that runs by default rather than only in an emergency.
         */
        $this->app->singleton(TagResolver::class, function (): TagResolver {
            $gemini = new GeminiTagResolver;

            return $gemini->isConfigured() ? $gemini : new NullTagResolver;
        });

        /*
         * The conversational provider, chosen the same way and for the same
         * reason. NullAiProvider is not a test double — it is what a
         * deployment with no API key actually runs, and the assistant degrades
         * to an honest "I cannot answer right now" rather than a 500.
         */
        /*
         * Per-request, and scoped rather than singleton for that reason: a
         * controller sets a title on it and a queued job in the same process
         * must not inherit that title.
         */
        $this->app->scoped(Seo::class);

        $this->app->singleton(AiProvider::class, function (): AiProvider {
            $gemini = new GeminiProvider;

            return $gemini->isConfigured() ? $gemini : new NullAiProvider;
        });
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        /*
         * HTTPS everywhere but local.
         *
         * Behind HestiaCP's proxy the application sees plain HTTP on the
         * loopback, so it would generate http:// URLs for a site served over
         * https:// — mixed content, and a payment redirect that some browsers
         * refuse outright.
         */
        if (! $this->app->environment('local')) {
            URL::forceScheme('https');
        }

        /*
         * Bust the catalogue cache whenever the catalogue changes.
         *
         * Hooked on the models rather than in the services that usually do the
         * writing, because "usually" is the problem: an admin panel edit, a
         * seeder, a queued auto-approval and a future service all change the
         * same numbers, and a bust wired into one of those paths is a bust the
         * other three skip. The failure mode is a listing count that stays
         * wrong until somebody restarts Redis, which nobody would connect to
         * the change that caused it.
         */
        foreach ([Product::class, Category::class] as $model) {
            $model::saved(fn () => app(CatalogueCache::class)->flush());
            $model::deleted(fn () => app(CatalogueCache::class)->flush());
        }

        // Registered explicitly rather than by convention: ownership of a
        // listing is the thing that keeps one seller out of another's records,
        // and it should be visible in one place rather than inferred.
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(SellerProfile::class, SellerProfilePolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(SubOrder::class, SubOrderPolicy::class);
        Gate::policy(Dispute::class, DisputePolicy::class);
        Gate::policy(Offer::class, OfferPolicy::class);
        Gate::policy(BuyerRequest::class, BuyerRequestPolicy::class);

        /*
         * The jobs board. JobRatingPolicy is the one that matters: the
         * requirement that a rating follows an actual hire lives there and
         * nowhere else, so it cannot be bypassed by reaching the endpoint from
         * a different direction.
         */
        Gate::policy(JobListing::class, JobListingPolicy::class);
        Gate::policy(JobRating::class, JobRatingPolicy::class);
        Gate::policy(WorkerProfile::class, WorkerProfilePolicy::class);
    }
}
