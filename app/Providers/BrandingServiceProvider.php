<?php

namespace App\Providers;

use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the platform's identity into the three places that need it:
 *
 *  1. Inertia pages — see App\Http\Middleware\HandleInertiaRequests.
 *  2. Blade templates — the view composer below, so mail, PDF and certificate
 *     templates receive exactly the same values as the front end.
 *  3. Admin-authored content — the `{company}` / `{company_short}` placeholder
 *     expansion, available as a helper, a Blade directive and a Str macro.
 */
class BrandingServiceProvider extends ServiceProvider
{
    /**
     * View prefixes that render the company's identity. Mail, PDFs and
     * certificates all live under these.
     *
     * @var array<int, string>
     */
    private const COMPOSED_VIEWS = [
        'app',
        'emails.*',
        'pdf.*',
        'certificates.*',
        'layouts.*',
        'components.brand.*',
        'components.mail.*',
        'filament.*',
    ];

    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(BrandingService::class);
    }

    public function boot(): void
    {
        View::composer(self::COMPOSED_VIEWS, function ($view): void {
            $branding = $this->app->make(BrandingService::class);

            $view->with('branding', $branding->payload())
                ->with('brand', $branding);
        });

        // @branded($copy) — expands {company} and friends in stored content.
        Blade::directive('branded', function (string $expression): string {
            return "<?php echo e(app(\\App\\Services\\Branding\\BrandingService::class)->replacePlaceholders({$expression})); ?>";
        });
    }
}
