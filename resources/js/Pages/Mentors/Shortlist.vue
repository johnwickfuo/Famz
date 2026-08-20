<script setup>
import { computed, ref } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import MentorCard from '@/Components/Mentors/MentorCard.vue';

/**
 * The shortlist.
 *
 * Two things this page is careful about. It says how the match was made —
 * a list built from keywords because the AI was down is still a list, and
 * pretending otherwise is how somebody loses trust in a good result. And the
 * comparison table puts the packages side by side, because "who is best" is
 * usually settled by what each one actually costs.
 */
const props = defineProps({
    match: { type: Object, required: true },
    mentors: { type: Array, default: () => [] },
});

const comparing = ref(false);

const heading = computed(() => {
    if (props.mentors.length === 0) {
        return 'Nobody matched that yet';
    }

    return props.mentors.length === 1 ? '1 person who can help' : `${props.mentors.length} people who can help`;
});

// Every package from every shortlisted mentor, cheapest first: the question a
// client actually asks is "what can I get for what I have".
const allPackages = computed(() =>
    props.mentors
        .flatMap((mentor) => (mentor.packages ?? []).map((pkg) => ({ ...pkg, mentor })))
        .sort((a, b) => a.price_kobo - b.price_kobo),
);
</script>

<template>
    <PublicLayout title="Mentors for you">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Mentors</p>
        <h1 class="text-2xl sm:text-3xl">{{ heading }}</h1>

        <Card class="mt-4">
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base">What you told us</h2>
                    <!--
                        Said plainly rather than hidden. A client is entitled to
                        know whether the ranking read their words or matched
                        their keywords.
                    -->
                    <Badge size="sm" :variant="match.resolver === 'keyword' ? 'neutral' : 'active'">
                        {{ match.resolver_label }}
                    </Badge>
                </div>
            </template>

            <p class="text-sm">{{ match.need }}</p>

            <div v-if="match.tags.length" class="mt-3 flex flex-wrap gap-1.5">
                <Badge v-for="tag in match.tags" :key="tag" size="sm" variant="pending">{{ tag }}</Badge>
            </div>

            <p class="figures mt-3 text-xs text-muted">
                <span v-if="match.state">{{ match.state }}</span>
                <span v-if="match.wants_in_person"> · wants a farm visit</span>
                <span v-if="match.budget_max"> · up to {{ match.budget_max }}</span>
            </p>

            <template #footer>
                <div class="flex flex-wrap gap-2">
                    <Button :href="route('mentors.find')" variant="secondary" size="sm">Change what I said</Button>
                    <Button v-if="mentors.length > 1" size="sm" @click="comparing = !comparing">
                        {{ comparing ? 'Hide comparison' : 'Compare prices' }}
                    </Button>
                </div>
            </template>
        </Card>

        <hr class="seam my-6" />

        <EmptyState
            v-if="!mentors.length"
            title="Nobody matched that yet"
            description="Try widening it — drop the state, or raise the budget. New mentors are added by invitation, so it is worth coming back."
        >
            <template #action>
                <Button :href="route('mentors.find')">Try again</Button>
            </template>
        </EmptyState>

        <template v-else>
            <!-- Prices side by side, cheapest first. -->
            <Card v-if="comparing" class="mb-6">
                <template #header>
                    <h2 class="text-base">Every package, cheapest first</h2>
                </template>

                <div class="-mx-1 overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-sm">
                        <thead>
                            <tr class="border-b-2 border-ink text-left dark:border-wash">
                                <th class="stencil px-4 py-2">Mentor</th>
                                <th class="stencil px-4 py-2">Package</th>
                                <th class="stencil px-4 py-2">What you get</th>
                                <th class="stencil px-4 py-2 text-right">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="pkg in allPackages"
                                :key="`${pkg.mentor.slug}-${pkg.id}`"
                                class="border-b border-dashed border-grain-300 dark:border-grain-700"
                            >
                                <td class="px-4 py-3">
                                    <a :href="pkg.mentor.url" class="underline underline-offset-4">
                                        {{ pkg.mentor.name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ pkg.title }}</td>
                                <td class="px-4 py-3 text-xs text-muted">
                                    {{ pkg.duration }}<span v-if="pkg.sessions"> · {{ pkg.sessions }} sessions</span>
                                </td>
                                <td class="figures px-4 py-3 text-right font-semibold">{{ pkg.price_label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <MentorCard
                    v-for="(mentor, index) in mentors"
                    :key="mentor.slug"
                    :mentor="mentor"
                    :rank="mentors.length > 1 ? index + 1 : null"
                />
            </div>
        </template>
    </PublicLayout>
</template>
