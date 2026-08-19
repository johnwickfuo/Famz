<?php

namespace App\Providers;

use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Policies\BuyerRequestPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\DisputePolicy;
use App\Policies\OfferPolicy;
use App\Policies\ProductPolicy;
use App\Policies\SellerProfilePolicy;
use App\Policies\SubOrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

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
    }
}
