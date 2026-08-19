<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import BrandMark from '@/Components/Brand/BrandMark.vue';
import Toast from '@/Components/Ui/Toast.vue';
import Button from '@/Components/Ui/Button.vue';

defineProps({
    title: { type: String, default: null },
});

const page = usePage();
const branding = computed(() => page.props.branding ?? {});

const user = computed(() => page.props.auth?.user ?? null);
const cartCount = computed(() => page.props.cart?.count ?? 0);
const unread = computed(() => page.props.notifications?.unread ?? 0);
const search = ref('');
const menuOpen = ref(false);

const nav = [
    { label: 'Marketplace', href: route('catalogue.home') },
    { label: 'Wanted', href: route('requests.index') },
    { label: 'Training', href: route('sections.show', 'training') },
    { label: 'Mentors', href: route('sections.show', 'mentors') },
    { label: 'Farm jobs', href: route('sections.show', 'jobs') },
];

function submitSearch() {
    router.get(route('search'), { q: search.value }, { preserveState: true });
}

const year = new Date().getFullYear();
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-dvh flex-col bg-surface text-body">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-sm focus:border-2 focus:border-ink focus:bg-chrome focus:px-3 focus:py-2 focus:font-display focus:text-xs focus:uppercase"
        >
            Skip to content
        </a>

        <header class="bg-enamel text-wash">
            <div class="mx-auto max-w-7xl px-3 py-3 sm:px-6">
                <!--
                    Two rows on a phone, one from `sm` up. Search is the primary
                    job on a marketplace, so below `sm` it gets a full row of its
                    own rather than being squeezed between the mark and the menu
                    button — at 360px that left room for about four characters.
                -->
                <div class="flex items-center gap-3">
                    <Link :href="route('home')" class="min-w-0 shrink" aria-label="Home">
                        <BrandMark tone="light" size="sm" />
                    </Link>

                    <form
                        class="ml-auto hidden min-w-0 flex-1 items-stretch sm:flex sm:max-w-md"
                        role="search"
                        @submit.prevent="submitSearch"
                    >
                        <label for="site-search" class="sr-only">Search the marketplace</label>
                        <input
                            id="site-search"
                            v-model="search"
                            type="search"
                            name="q"
                            placeholder="Feed, cages, day-old chicks…"
                            class="min-w-0 flex-1 rounded-l-sm border-2 border-r-0 border-ink bg-wash px-3 py-2 text-sm text-ink placeholder:text-grain-400"
                        />
                        <button
                            type="submit"
                            class="shrink-0 rounded-r-sm border-2 border-ink bg-chrome px-3 text-ink"
                        >
                            <span class="sr-only">Search</span>
                            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <circle cx="9" cy="9" r="6" />
                                <path d="m13.5 13.5 4 4" />
                            </svg>
                        </button>
                    </form>

                    <Link
                        v-if="user"
                        :href="route('notifications.index')"
                        class="relative ml-auto shrink-0 rounded-sm border-2 border-wash p-2 sm:ml-0"
                    >
                        <span class="sr-only">
                            What has happened<span v-if="unread">, {{ unread }} unread</span>
                        </span>
                        <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M5 8a5 5 0 0 1 10 0c0 4 1.5 5 1.5 5h-13S5 12 5 8Z" stroke-linejoin="round" />
                            <path d="M8.5 16a1.8 1.8 0 0 0 3 0" stroke-linecap="round" />
                        </svg>
                        <span
                            v-if="unread"
                            class="figures absolute -right-2 -top-2 min-w-5 rounded-full border-2 border-ink bg-cockscomb px-1 text-2xs font-bold leading-4 text-wash"
                            aria-hidden="true"
                        >{{ unread > 99 ? '99+' : unread }}</span>
                    </Link>

                    <Link
                        :href="route('cart.index')"
                        class="relative shrink-0 rounded-sm border-2 border-wash p-2"
                        :class="user ? '' : 'ml-auto sm:ml-0'"
                    >
                        <span class="sr-only">
                            Cart<span v-if="cartCount">, {{ cartCount }} item{{ cartCount === 1 ? '' : 's' }}</span>
                        </span>
                        <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M2 3h2.2l1.9 9.2h8.6L16.5 6H6" stroke-linecap="square" />
                            <circle cx="7.5" cy="16.5" r="1.2" fill="currentColor" stroke="none" />
                            <circle cx="14" cy="16.5" r="1.2" fill="currentColor" stroke="none" />
                        </svg>
                        <span
                            v-if="cartCount"
                            class="figures absolute -right-2 -top-2 min-w-5 rounded-full border-2 border-ink bg-chrome px-1 text-2xs font-bold leading-4 text-ink"
                            aria-hidden="true"
                        >{{ cartCount > 99 ? '99+' : cartCount }}</span>
                    </Link>

                    <button
                        type="button"
                        class="shrink-0 rounded-sm border-2 border-wash p-2 lg:hidden"
                        :aria-expanded="menuOpen"
                        aria-controls="primary-nav"
                        @click="menuOpen = !menuOpen"
                    >
                        <span class="sr-only">{{ menuOpen ? 'Close menu' : 'Open menu' }}</span>
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path v-if="!menuOpen" d="M2 4h16v2H2V4Zm0 5h16v2H2V9Zm0 5h16v2H2v-2Z" />
                            <path v-else d="M5.3 4.3 10 9l4.7-4.7 1 1L11 10l4.7 4.7-1 1L10 11l-4.7 4.7-1-1L9 10 4.3 5.3l1-1Z" />
                        </svg>
                    </button>
                </div>

                <form
                    class="mt-3 flex items-stretch sm:hidden"
                    role="search"
                    @submit.prevent="submitSearch"
                >
                    <label for="site-search-mobile" class="sr-only">Search the marketplace</label>
                    <input
                        id="site-search-mobile"
                        v-model="search"
                        type="search"
                        name="q"
                        placeholder="Feed, cages, day-old chicks…"
                        class="min-w-0 flex-1 rounded-l-sm border-2 border-r-0 border-ink bg-wash px-3 py-2.5 text-base text-ink placeholder:text-grain-400"
                    />
                    <button
                        type="submit"
                        class="shrink-0 rounded-r-sm border-2 border-ink bg-chrome px-4 text-ink"
                    >
                        <span class="sr-only">Search</span>
                        <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <circle cx="9" cy="9" r="6" />
                            <path d="m13.5 13.5 4 4" />
                        </svg>
                    </button>
                </form>
            </div>

            <hr class="seam seam-chrome mx-3 sm:mx-6" />

            <nav
                id="primary-nav"
                class="mx-auto max-w-7xl px-3 sm:px-6"
                :class="menuOpen ? 'block' : 'hidden lg:block'"
                aria-label="Primary"
            >
                <ul class="flex flex-col gap-1 py-2 lg:flex-row lg:items-center lg:gap-6">
                    <li v-for="item in nav" :key="item.label">
                        <Link
                            :href="item.href"
                            class="stencil block py-2 text-wash/90 underline-offset-8 hover:text-chrome hover:underline"
                        >
                            {{ item.label }}
                        </Link>
                    </li>

                    <li class="lg:ml-auto">
                        <Link
                            v-if="!user"
                            :href="route('login')"
                            class="stencil block py-2 text-wash/90 underline-offset-8 hover:text-chrome hover:underline"
                        >
                            Sign in
                        </Link>
                        <Link
                            v-else
                            :href="route('dashboard')"
                            class="stencil block py-2 text-wash/90 underline-offset-8 hover:text-chrome hover:underline"
                        >
                            My dashboard
                        </Link>
                    </li>
                    <li v-if="user">
                        <Link
                            :href="route('orders.index')"
                            class="stencil block py-2 text-wash/90 underline-offset-8 hover:text-chrome hover:underline"
                        >
                            My orders
                        </Link>
                    </li>
                    <li v-if="user">
                        <Link
                            :href="route('requests.mine')"
                            class="stencil block py-2 text-wash/90 underline-offset-8 hover:text-chrome hover:underline"
                        >
                            My requests
                        </Link>
                    </li>
                    <li v-if="user">
                        <Link
                            :href="route('disputes.index')"
                            class="stencil block py-2 text-wash/90 underline-offset-8 hover:text-chrome hover:underline"
                        >
                            Problems
                        </Link>
                    </li>
                    <li v-if="!user" class="py-2">
                        <Button :href="route('register')" size="sm">Join free</Button>
                    </li>
                </ul>
            </nav>
        </header>

        <main id="main" class="mx-auto w-full max-w-7xl flex-1 px-3 py-6 sm:px-6 sm:py-10">
            <slot />
        </main>

        <footer class="mt-auto bg-ink text-wash">
            <div class="mx-auto max-w-7xl px-3 py-8 sm:px-6">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <BrandMark tone="light" size="md" />
                        <p v-if="branding.tagline" class="mt-3 max-w-sm text-sm text-wash/70">
                            {{ branding.tagline }}
                        </p>
                    </div>

                    <address class="min-w-0 space-y-1 text-sm not-italic text-wash/70">
                        <p v-if="branding.address">{{ branding.address }}</p>
                        <p v-if="branding.phone" class="figures">
                            <a :href="`tel:${branding.phone}`" class="underline-offset-4 hover:underline">{{ branding.phone }}</a>
                        </p>
                        <p v-if="branding.whatsapp" class="figures">
                            WhatsApp
                            <a :href="`https://wa.me/${branding.whatsapp.replace(/\D/g, '')}`" class="underline-offset-4 hover:underline">{{ branding.whatsapp }}</a>
                        </p>
                        <p v-if="branding.email">
                            <a :href="`mailto:${branding.email}`" class="underline-offset-4 hover:underline">{{ branding.email }}</a>
                        </p>
                    </address>
                </div>

                <hr class="seam seam-chrome my-6" />

                <div class="flex flex-col gap-3 text-xs text-wash/60 sm:flex-row sm:items-center sm:justify-between">
                    <p>
                        <span class="figures">&copy; {{ year }}</span>
                        {{ branding.name }}<span v-if="branding.rc_number" class="figures"> · RC {{ branding.rc_number }}</span>
                    </p>

                    <ul v-if="Object.keys(branding.social_links ?? {}).length" class="flex flex-wrap gap-4">
                        <li v-for="(url, platform) in branding.social_links" :key="platform">
                            <a :href="url" rel="noopener" class="stencil underline-offset-4 hover:text-chrome hover:underline">
                                {{ platform }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </footer>

        <Toast />
    </div>
</template>
