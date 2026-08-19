<script setup>
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import ProgressBar from '@/Components/Academy/ProgressBar.vue';

/**
 * One course, as it appears in a grid.
 *
 * The same card does the catalogue and the student's own shelf. What changes is
 * whether a progress bar is passed in — a course somebody owns is described by
 * how far through it they are, not by what it costs.
 */
defineProps({
    course: { type: Object, required: true },
    /** Show the enrolled treatment: progress instead of price. */
    owned: { type: Boolean, default: false },
});
</script>

<template>
    <article
        class="flex h-full flex-col rounded-sm border-2 border-ink bg-surface-raised shadow-offset dark:border-wash dark:bg-grain-900"
    >
        <Link
            :href="owned ? (course.player_url ?? course.url) : course.url"
            class="block border-b-2 border-ink dark:border-wash"
        >
            <img
                v-if="course.cover"
                :src="course.cover"
                :alt="''"
                class="aspect-[3/2] w-full object-cover"
                loading="lazy"
            />
            <!-- No cover: a painted panel rather than a broken frame. -->
            <div
                v-else
                class="flex aspect-[3/2] w-full items-center justify-center bg-enamel px-4 text-center"
                aria-hidden="true"
            >
                <span class="wordmark wordmark-long text-sm text-wash">{{ course.title }}</span>
            </div>
        </Link>

        <div class="flex flex-1 flex-col p-3 sm:p-4">
            <div class="mb-2 flex flex-wrap items-center gap-1.5">
                <Badge v-if="course.category" size="sm" variant="neutral">{{ course.category }}</Badge>
                <Badge size="sm" variant="neutral">{{ course.level }}</Badge>
            </div>

            <h3 class="text-base leading-snug">
                <Link
                    :href="owned ? (course.player_url ?? course.url) : course.url"
                    class="underline-offset-4 hover:underline"
                >
                    {{ course.title }}
                </Link>
            </h3>

            <p v-if="course.summary" class="mt-1 line-clamp-2 text-sm text-muted">{{ course.summary }}</p>

            <p class="figures mt-2 text-xs text-muted">
                {{ course.lesson_count }} lesson{{ course.lesson_count === 1 ? '' : 's' }}
                <span v-if="course.minutes"> · {{ course.minutes }} min</span>
                <span v-if="course.students"> · {{ course.students }} enrolled</span>
            </p>

            <div class="mt-auto pt-3">
                <template v-if="owned">
                    <ProgressBar :percent="course.progress ?? 0" size="sm" :label="null" />
                    <p v-if="course.last_seen" class="mt-1.5 text-xs text-muted">Last opened {{ course.last_seen }}</p>
                </template>

                <p v-else class="figures text-lg font-bold">
                    <span v-if="course.is_free" class="text-enamel dark:text-enamel-200">Free</span>
                    <span v-else>{{ course.price }}</span>
                </p>
            </div>
        </div>
    </article>
</template>
