<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import BrandMark from '@/Components/Brand/BrandMark.vue';
import Toast from '@/Components/Ui/Toast.vue';
import Breadcrumb from '@/Components/Ui/Breadcrumb.vue';
import Badge from '@/Components/Ui/Badge.vue';

defineProps({
    title: { type: String, default: null },
    breadcrumbs: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const roles = computed(() => user.value?.roles ?? []);
const sidebarOpen = ref(false);

/**
 * The sidebar shows the panels this account can actually open. Roles are
 * additive, so a user may see several at once.
 */
const panels = [
    { role: 'admin', label: 'Admin panel', path: '/admin' },
    { role: 'seller', label: 'Seller panel', path: '/seller' },
    { role: 'mentor', label: 'Mentor panel', path: '/mentor' },
];

const availablePanels = computed(() => panels.filter((panel) => roles.value.includes(panel.role)));

const nav = computed(() => [
    { label: 'Dashboard', href: route('dashboard'), icon: 'grid' },
    { label: 'My profile', href: route('profile.edit'), icon: 'user' },
]);

const icons = {
    grid: 'M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h7v7h-7v-7Z',
    user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 9a8 8 0 0 1 16 0',
    panel: 'M4 4h16v16H4V4Zm0 5h16M9 9v11',
};
</script>

<template>
    <Head :title="title" />

    <div class="min-h-dvh bg-surface text-body">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-sm focus:border-2 focus:border-ink focus:bg-chrome focus:px-3 focus:py-2 focus:font-display focus:text-xs focus:uppercase"
        >
            Skip to content
        </a>

        <!-- Mobile bar -->
        <div class="flex items-center gap-3 bg-enamel px-3 py-3 lg:hidden">
            <button
                type="button"
                class="rounded-sm border-2 border-wash p-2 text-wash"
                :aria-expanded="sidebarOpen"
                aria-controls="app-sidebar"
                @click="sidebarOpen = !sidebarOpen"
            >
                <span class="sr-only">{{ sidebarOpen ? 'Close menu' : 'Open menu' }}</span>
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path v-if="!sidebarOpen" d="M2 4h16v2H2V4Zm0 5h16v2H2V9Zm0 5h16v2H2v-2Z" />
                    <path v-else d="M5.3 4.3 10 9l4.7-4.7 1 1L11 10l4.7 4.7-1 1L10 11l-4.7 4.7-1-1L9 10 4.3 5.3l1-1Z" />
                </svg>
            </button>

            <Link :href="route('home')" aria-label="Home">
                <BrandMark tone="light" size="sm" />
            </Link>
        </div>

        <div class="lg:flex">
            <aside
                id="app-sidebar"
                class="w-full shrink-0 bg-ink text-wash lg:sticky lg:top-0 lg:h-dvh lg:w-72 lg:overflow-y-auto"
                :class="sidebarOpen ? 'block' : 'hidden lg:block'"
            >
                <div class="hidden px-4 py-5 lg:block">
                    <Link :href="route('home')" aria-label="Home">
                        <BrandMark tone="light" size="md" />
                    </Link>
                </div>

                <hr class="seam seam-chrome mx-4 hidden lg:block" />

                <nav class="px-3 py-4" aria-label="Account">
                    <ul class="space-y-1">
                        <li v-for="item in nav" :key="item.label">
                            <Link
                                :href="item.href"
                                class="flex items-center gap-3 rounded-sm px-3 py-2.5 text-sm font-semibold text-wash/85 hover:bg-grain-800 hover:text-chrome"
                            >
                                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path :d="icons[item.icon]" />
                                </svg>
                                {{ item.label }}
                            </Link>
                        </li>
                    </ul>

                    <template v-if="availablePanels.length">
                        <hr class="seam my-4" />
                        <p class="stencil px-3 pb-2 text-wash/50">Workspaces</p>
                        <ul class="space-y-1">
                            <li v-for="panel in availablePanels" :key="panel.role">
                                <a
                                    :href="panel.path"
                                    class="flex items-center gap-3 rounded-sm px-3 py-2.5 text-sm font-semibold text-wash/85 hover:bg-grain-800 hover:text-chrome"
                                >
                                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path :d="icons.panel" />
                                    </svg>
                                    {{ panel.label }}
                                </a>
                            </li>
                        </ul>
                    </template>
                </nav>

                <hr class="seam mx-4" />

                <div class="px-4 py-4">
                    <p class="truncate text-sm font-semibold">{{ user?.display_name }}</p>
                    <p class="truncate text-xs text-wash/60">{{ user?.email }}</p>

                    <div class="mt-2 flex flex-wrap gap-1">
                        <Badge v-for="role in roles" :key="role" variant="ink" size="sm">{{ role }}</Badge>
                    </div>

                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="stencil mt-4 text-cockscomb-300 underline underline-offset-4"
                    >
                        Sign out
                    </Link>
                </div>
            </aside>

            <div class="min-w-0 flex-1">
                <main id="main" class="mx-auto w-full max-w-5xl px-3 py-6 sm:px-6 sm:py-8">
                    <Breadcrumb v-if="breadcrumbs.length" :items="breadcrumbs" class="mb-4" />

                    <header v-if="title || $slots.header" class="mb-6">
                        <h1 v-if="title" class="text-2xl sm:text-3xl">{{ title }}</h1>
                        <slot name="header" />
                        <hr class="seam mt-4" />
                    </header>

                    <slot />
                </main>
            </div>
        </div>

        <Toast />
    </div>
</template>
