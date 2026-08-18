<script setup>
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';

defineProps({
    seller: { type: Object, required: true },
});
</script>

<template>
    <Card>
        <template #header>
            <p class="stencil text-muted">Sold by</p>
        </template>

        <div class="flex items-start gap-3">
            <img
                v-if="seller.logo_url"
                :src="seller.logo_url"
                :alt="seller.business_name"
                class="size-12 shrink-0 rounded-sm border-2 border-ink object-contain dark:border-wash"
                loading="lazy"
                decoding="async"
            />
            <span
                v-else
                class="flex size-12 shrink-0 items-center justify-center rounded-sm border-2 border-ink bg-chrome-100 font-display text-sm font-bold dark:border-wash dark:bg-grain-800"
                aria-hidden="true"
            >{{ seller.business_name.slice(0, 2).toUpperCase() }}</span>

            <div class="min-w-0">
                <p class="font-semibold leading-snug">
                    <Link :href="route('catalogue.storefront', seller.slug)" class="hover:underline">
                        {{ seller.business_name }}
                    </Link>
                </p>
                <p v-if="seller.location" class="text-sm text-muted">{{ seller.location }}</p>
            </div>
        </div>

        <dl class="mt-4 space-y-2 text-sm">
            <div class="flex items-baseline justify-between gap-3">
                <dt class="stencil text-muted">Rating</dt>
                <dd v-if="seller.rating" class="figures font-semibold">{{ seller.rating }}</dd>
                <!-- Ratings arrive with orders in a later phase; saying so beats
                     showing five empty stars as though nobody liked them. -->
                <dd v-else class="text-muted">Not rated yet</dd>
            </div>

            <div class="flex items-baseline justify-between gap-3">
                <dt class="stencil text-muted">Member since</dt>
                <dd class="figures">{{ seller.member_since }}</dd>
            </div>

            <div class="flex items-baseline justify-between gap-3">
                <dt class="stencil text-muted">Listings</dt>
                <dd class="figures">{{ seller.listing_count }}</dd>
            </div>
        </dl>

        <template #footer>
            <Button :href="route('catalogue.storefront', seller.slug)" variant="secondary" size="sm" block>
                See all their listings
            </Button>
        </template>
    </Card>
</template>
