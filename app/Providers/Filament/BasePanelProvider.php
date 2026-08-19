<?php

namespace App\Providers\Filament;

use App\Enums\RoleName;
use App\Http\Middleware\EnsurePanelRole;
use App\Services\Branding\BrandingService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Everything the three panels have in common: the shared theme, the shared
 * palette, and the rule that a panel is gated by the role whose name matches
 * its id.
 *
 * The brand name, logo and favicon are resolved through BrandingService at
 * render time, so renaming the company in Settings changes every panel too.
 */
abstract class BasePanelProvider extends PanelProvider
{
    abstract protected function role(): RoleName;

    public function panel(Panel $panel): Panel
    {
        $branding = fn (): BrandingService => app(BrandingService::class);
        $role = $this->role();
        $id = $role->value;

        return $panel
            ->id($id)
            ->path(config("platform.panels.{$id}", $id))
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)
            ->colors($this->colors())
            ->viteTheme('resources/css/filament/panel.css')
            ->brandName(fn (): string => $branding()->name())
            ->brandLogo(fn (): ?string => $branding()->logoUrl())
            ->darkModeBrandLogo(fn (): ?string => $branding()->logoDarkUrl())
            ->brandLogoHeight('2rem')
            ->favicon(fn (): ?string => $branding()->faviconUrl())
            ->discoverResources(
                in: app_path("Filament/{$this->directory()}/Resources"),
                for: "App\\Filament\\{$this->directory()}\\Resources",
            )
            ->discoverPages(
                in: app_path("Filament/{$this->directory()}/Pages"),
                for: "App\\Filament\\{$this->directory()}\\Pages",
            )
            ->discoverWidgets(
                in: app_path("Filament/{$this->directory()}/Widgets"),
                for: "App\\Filament\\{$this->directory()}\\Widgets",
            )
            ->pages([$this->dashboardPage()])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePanelRole::class.':'.$id,
            ]);
    }

    /**
     * The page a panel opens on.
     *
     * A panel whose landing page is a row of empty cards teaches nobody
     * anything, so a panel with something worth showing replaces this.
     *
     * @return class-string
     */
    protected function dashboardPage(): string
    {
        return Dashboard::class;
    }

    /**
     * The panel's own directory under app/Filament, e.g. "Admin".
     */
    protected function directory(): string
    {
        return str($this->role()->value)->studly()->value();
    }

    /**
     * The platform palette, shared by all three panels. See
     * docs/design-system.md — enamel is structure, chrome is the one call to
     * action, cockscomb is danger only.
     *
     * @return array<string, array<int, string>|string>
     */
    protected function colors(): array
    {
        return [
            'primary' => Color::hex('#0E5138'),
            'gray' => Color::hex('#575A52'),
            'danger' => Color::hex('#C22A1B'),
            'warning' => Color::hex('#F5B711'),
            'success' => Color::hex('#0E5138'),
            'info' => Color::hex('#3F423A'),
        ];
    }
}
