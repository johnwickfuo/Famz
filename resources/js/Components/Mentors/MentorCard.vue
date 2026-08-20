<script setup>
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';

/**
 * One mentor, as they appear in a shortlist.
 *
 * Note what is not on this card and never can be: a phone number, an email, a
 * meeting link. The props it is given come from MentorProfile::publicCard(),
 * which has no contact field in it at all — so there is nothing here to
 * accidentally render.
 */
defineProps({
    mentor: { type: Object, required: true },
    /** Position in the shortlist, 1-based. Shown only when ranked. */
    rank: { type: Number, default: null },
});
</script>

<template>
    <article
        class="flex h-full flex-col rounded-sm border-2 border-ink bg-surface-raised p-4 shadow-offset dark:border-wash dark:bg-grain-900"
    >
        <div class="flex items-start gap-3">
            <img
                v-if="mentor.avatar"
                :src="mentor.avatar"
                alt=""
                class="size-14 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
                loading="lazy"
            />
            <div
                v-else
                class="flex size-14 shrink-0 items-center justify-center rounded-sm border-2 border-ink bg-enamel text-lg font-bold text-wash dark:border-wash"
                aria-hidden="true"
            >
                {{ mentor.name.charAt(0) }}
            </div>

            <div class="min-w-0 flex-1">
                <h3 class="text-base leading-snug">
                    <Link :href="mentor.url" class="underline-offset-4 hover:underline">{{ mentor.name }}</Link>
                </h3>
                <p class="text-sm text-muted">{{ mentor.headline }}</p>
            </div>

            <span v-if="rank" class="stencil shrink-0 text-muted">#{{ rank }}</span>
        </div>

        <p class="figures mt-3 text-xs text-muted">
            <span v-if="mentor.years_experience">{{ mentor.years_experience }} years</span>
            <span v-if="mentor.rating"> · {{ mentor.rating }}/5 from {{ mentor.reviews_count }}</span>
            <span v-else> · no reviews yet</span>
            <span v-if="mentor.engagements_completed"> · {{ mentor.engagements_completed }} finished</span>
        </p>

        <!-- Why this one came up. -->
        <div v-if="mentor.matched?.length" class="mt-3 flex flex-wrap gap-1.5">
            <Badge v-for="tag in mentor.matched" :key="tag" size="sm" variant="active">{{ tag }}</Badge>
        </div>
        <div v-else-if="mentor.specialisations?.length" class="mt-3 flex flex-wrap gap-1.5">
            <Badge v-for="tag in mentor.specialisations.slice(0, 3)" :key="tag.slug" size="sm" variant="neutral">
                {{ tag.name }}
            </Badge>
        </div>

        <p class="mt-3 text-xs text-muted">
            {{ mentor.contact_method }}<span v-if="mentor.accepts_in_person">, or visits the farm</span>
            <span v-if="mentor.states_served?.length"> · {{ mentor.states_served.join(', ') }}</span>
        </p>

        <div class="mt-auto flex flex-wrap items-end justify-between gap-2 pt-3">
            <p class="figures text-lg font-bold">
                <span v-if="mentor.from_price" class="text-sm font-normal text-muted">from </span>
                {{ mentor.from_price ?? '—' }}
            </p>

            <span v-if="mentor.affordable === false" class="stencil text-cockscomb">Over your budget</span>
        </div>
    </article>
</template>
