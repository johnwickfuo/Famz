import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The platform's identity, shared onto every Inertia page by
 * App\Http\Middleware\HandleInertiaRequests.
 *
 * This is the only way a Vue component may learn the company name. Nothing in
 * resources/js is allowed to write it as a literal.
 */
export function useBranding() {
    const page = usePage();

    const branding = computed(() => page.props.branding ?? {});

    const name = computed(() => branding.value.name ?? '');
    const shortName = computed(() => branding.value.short_name || name.value);

    /**
     * How much room the wordmark needs. Drives the tracking so a two-letter
     * name and a six-word name both read as a designed mark.
     */
    const nameLength = computed(() => (name.value ?? '').length);

    const wordmarkClass = computed(() => {
        if (nameLength.value <= 10) return 'wordmark wordmark-short';
        if (nameLength.value >= 22) return 'wordmark wordmark-long';
        return 'wordmark';
    });

    return {
        branding,
        name,
        shortName,
        wordmarkClass,
        tagline: computed(() => branding.value.tagline ?? null),
        email: computed(() => branding.value.email ?? null),
        phone: computed(() => branding.value.phone ?? null),
        whatsapp: computed(() => branding.value.whatsapp ?? null),
        address: computed(() => branding.value.address ?? null),
        rcNumber: computed(() => branding.value.rc_number ?? null),
        logoUrl: computed(() => branding.value.logo_url ?? null),
        logoDarkUrl: computed(() => branding.value.logo_dark_url ?? null),
        socialLinks: computed(() => branding.value.social_links ?? {}),
        hasLogo: computed(() => Boolean(branding.value.has_logo)),
        initials: computed(() => branding.value.initials ?? ''),
    };
}
