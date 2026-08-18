<script setup>
import { Head, Link } from '@inertiajs/vue3';
import BrandMark from '@/Components/Brand/BrandMark.vue';
import Toast from '@/Components/Ui/Toast.vue';

defineProps({
    title: { type: String, default: null },
    heading: { type: String, default: null },
    subheading: { type: String, default: null },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-dvh flex-col bg-surface">
        <div class="bg-enamel px-3 py-4 sm:px-6">
            <Link :href="route('home')" class="inline-block" aria-label="Home">
                <BrandMark tone="light" size="sm" />
            </Link>
        </div>

        <main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-3 py-8 sm:px-0">
            <div class="rounded-sm border-2 border-ink bg-surface-raised p-5 shadow-offset-lg sm:p-6 dark:border-wash dark:bg-grain-900">
                <h1 v-if="heading" class="text-xl text-ink dark:text-wash">{{ heading }}</h1>
                <p v-if="subheading" class="mt-2 text-sm text-muted">{{ subheading }}</p>

                <hr v-if="heading" class="seam my-5" />

                <slot />
            </div>
        </main>

        <Toast />
    </div>
</template>
