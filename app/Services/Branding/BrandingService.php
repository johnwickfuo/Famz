<?php

namespace App\Services\Branding;

use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The single source of truth for who this platform belongs to.
 *
 * Nothing else in the application may name the company. Blade templates, Vue
 * components, notifications, PDFs and certificates all read from here, so the
 * administrator can rename the platform from the settings screen and see it
 * everywhere without a code change or a redeploy.
 */
class BrandingService
{
    public const CACHE_KEY = 'branding:payload';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Everything a template needs, in one cached array.
     *
     * @return array{
     *     name: string,
     *     short_name: string,
     *     tagline: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     whatsapp: string|null,
     *     address: string|null,
     *     rc_number: string|null,
     *     logo_url: string|null,
     *     logo_dark_url: string|null,
     *     favicon_url: string|null,
     *     social_links: array<string, string>,
     *     has_logo: bool,
     *     initials: string,
     * }
     */
    public function payload(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            return $this->memo = Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->build());
        } catch (\Throwable) {
            /*
             * The database or the cache store is unreachable.
             *
             * This is not a hypothetical: the maintenance page is rendered
             * exactly when something is down, and it still has to say whose
             * platform the visitor is looking at. Everything else in the
             * application is forbidden from naming the company, so the fallback
             * has to live here rather than at each call site — otherwise every
             * template grows its own, and the rule stops meaning anything.
             *
             * Not memoised, so a transient outage does not pin the degraded
             * payload in memory for the rest of the request lifetime.
             */
            return $this->degraded();
        }
    }

    /**
     * The least this can say while still being true, when settings are
     * unreachable.
     *
     * @return array<string, mixed>
     */
    private function degraded(): array
    {
        $name = trim((string) config('app.name'));

        return [
            'name' => $name,
            'short_name' => $name,
            'tagline' => null,
            'email' => null,
            'phone' => null,
            'whatsapp' => null,
            'address' => null,
            'rc_number' => null,
            'signatory_name' => null,
            'signatory_title' => null,
            // No logo rather than a broken image: the asset lives on a disk
            // that may be the thing that is down.
            'logo_url' => null,
            'logo_dark_url' => null,
            'favicon_url' => null,
            'social_links' => [],
            'has_logo' => false,
            'initials' => $this->initialsFor($name),
        ];
    }

    public function name(): string
    {
        return $this->payload()['name'];
    }

    public function shortName(): string
    {
        return $this->payload()['short_name'];
    }

    public function tagline(): ?string
    {
        return $this->payload()['tagline'];
    }

    public function email(): ?string
    {
        return $this->payload()['email'];
    }

    public function phone(): ?string
    {
        return $this->payload()['phone'];
    }

    public function whatsapp(): ?string
    {
        return $this->payload()['whatsapp'];
    }

    public function address(): ?string
    {
        return $this->payload()['address'];
    }

    public function signatoryName(): ?string
    {
        return $this->payload()['signatory_name'];
    }

    public function signatoryTitle(): ?string
    {
        return $this->payload()['signatory_title'];
    }

    public function rcNumber(): ?string
    {
        return $this->payload()['rc_number'];
    }

    public function logoUrl(): ?string
    {
        return $this->payload()['logo_url'];
    }

    public function logoDarkUrl(): ?string
    {
        return $this->payload()['logo_dark_url'];
    }

    public function faviconUrl(): ?string
    {
        return $this->payload()['favicon_url'];
    }

    /**
     * @return array<string, string>
     */
    public function socialLinks(): array
    {
        return $this->payload()['social_links'];
    }

    public function hasLogo(): bool
    {
        return $this->payload()['has_logo'];
    }

    /**
     * Up to two letters, for the places too small to hold a wordmark.
     */
    public function initials(): string
    {
        return $this->payload()['initials'];
    }

    /**
     * Expand the placeholders an administrator may type into content they
     * author — page copy, email overrides, certificate wording — so that
     * stored content survives a rename too.
     */
    public function replacePlaceholders(?string $content): ?string
    {
        if (blank($content)) {
            return $content;
        }

        return str_replace(
            array_keys($this->placeholders()),
            array_values($this->placeholders()),
            $content,
        );
    }

    /**
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        $payload = $this->payload();

        return [
            '{company}' => $payload['name'],
            '{company_short}' => $payload['short_name'],
            '{company_tagline}' => $payload['tagline'] ?? '',
            '{company_email}' => $payload['email'] ?? '',
            '{company_phone}' => $payload['phone'] ?? '',
            '{company_whatsapp}' => $payload['whatsapp'] ?? '',
            '{company_address}' => $payload['address'] ?? '',
            '{company_rc_number}' => $payload['rc_number'] ?? '',
        ];
    }

    /**
     * Forget the cached identity. Called the moment a branding setting is
     * written, so a rename is visible on the very next request.
     */
    public function flush(): void
    {
        $this->memo = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        // Last-resort fallback only. APP_NAME is a placeholder set in the
        // environment; the real name is the `company_name` setting.
        $name = $this->settings->string(BrandingKey::Name->value) ?? (string) config('app.name');
        $name = trim($name);

        $shortName = trim((string) ($this->settings->string(BrandingKey::ShortName->value) ?? '')) ?: $name;

        $logo = $this->assetUrl($this->settings->string(BrandingKey::Logo->value));
        $logoDark = $this->assetUrl($this->settings->string(BrandingKey::LogoDark->value));

        return [
            'name' => $name,
            'short_name' => $shortName,
            'tagline' => $this->settings->string(BrandingKey::Tagline->value),
            'email' => $this->settings->string(BrandingKey::Email->value),
            'phone' => $this->settings->string(BrandingKey::Phone->value),
            'whatsapp' => $this->settings->string(BrandingKey::Whatsapp->value),
            'address' => $this->settings->string(BrandingKey::Address->value),
            'rc_number' => $this->settings->string(BrandingKey::RcNumber->value),
            'signatory_name' => $this->settings->string(BrandingKey::SignatoryName->value),
            'signatory_title' => $this->settings->string(BrandingKey::SignatoryTitle->value),
            'logo_url' => $logo,
            'logo_dark_url' => $logoDark ?? $logo,
            'favicon_url' => $this->assetUrl($this->settings->string(BrandingKey::Favicon->value)),
            'social_links' => $this->socialLinksFromSettings(),
            'has_logo' => $logo !== null,
            'initials' => $this->initialsFor($name),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function socialLinksFromSettings(): array
    {
        $links = $this->settings->array(BrandingKey::SocialLinks->value, []) ?? [];

        return collect($links)
            ->filter(fn ($url): bool => is_string($url) && filled($url))
            ->map(fn (string $url): string => $url)
            ->all();
    }

    private function assetUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        // Already an absolute URL — an admin may have pasted a CDN address.
        if (Str::startsWith($path, ['http://', 'https://', '//', 'data:'])) {
            return $path;
        }

        return $this->disk()->url($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('branding.disk', config('filesystems.default')));
    }

    /**
     * Initials for a wordmark that has to fit in a very small square.
     */
    private function initialsFor(string $name): string
    {
        $words = preg_split('/[\s\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        if (count($words) === 1) {
            return Str::upper(Str::substr($words[0], 0, 2));
        }

        return Str::upper(Str::substr($words[0], 0, 1).Str::substr($words[1], 0, 1));
    }
}
