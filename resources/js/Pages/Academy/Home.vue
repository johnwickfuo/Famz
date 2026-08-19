<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import CourseCard from '@/Components/Academy/CourseCard.vue';

/**
 * The academy's front door.
 *
 * A student who already owns something sees that first: the most common reason
 * to come here at all is to carry on with what you started.
 */
defineProps({
    categories: { type: Array, default: () => [] },
    featured: { type: Array, default: () => [] },
    newest: { type: Array, default: () => [] },
    myCourses: { type: Array, default: () => [] },
});
</script>

<template>
    <PublicLayout title="Training">
        <section>
            <p class="stencil mb-2 text-enamel dark:text-chrome">Training</p>
            <h1 class="text-2xl sm:text-3xl">Learn the work, then get it in writing</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted">
                Short, practical courses written for farms here — brooding, feed, records, disease, market pricing. Buy
                once and it is yours; finish it and pass the questions at the end, and you get a certificate an employer
                can check online.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <Button :href="route('academy.catalogue')">Browse courses</Button>
                <Button v-if="myCourses.length" :href="route('academy.mine')" variant="secondary">My courses</Button>
            </div>

            <hr class="seam seam-chrome my-6" />
        </section>

        <!-- Carry on where you were. -->
        <section v-if="myCourses.length" class="mb-10" aria-labelledby="mine-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <h2 id="mine-heading" class="text-xl">Carry on</h2>
                <Button :href="route('academy.mine')" variant="secondary" size="sm">All my courses</Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <CourseCard v-for="course in myCourses" :key="course.slug" :course="course" owned />
            </div>
        </section>

        <section v-if="categories.length" class="mb-10" aria-labelledby="categories-heading">
            <h2 id="categories-heading" class="mb-4 text-xl">What do you want to learn?</h2>

            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <li v-for="category in categories" :key="category.slug">
                    <Link
                        :href="route('academy.catalogue', { category: category.slug })"
                        class="flex h-full flex-col gap-1 rounded-sm border-2 border-ink bg-surface-raised p-3 shadow-offset hover:bg-chrome-50 dark:border-wash dark:bg-grain-900 dark:hover:bg-grain-800"
                    >
                        <span class="font-display text-sm font-bold uppercase tracking-wide">{{ category.name }}</span>
                        <span class="figures text-2xs text-muted">
                            {{ category.course_count }} course{{ category.course_count === 1 ? '' : 's' }}
                        </span>
                        <span v-if="category.description" class="mt-1 line-clamp-2 text-xs text-muted">
                            {{ category.description }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <section aria-labelledby="featured-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <h2 id="featured-heading" class="text-xl">Courses to start with</h2>
                <Button :href="route('academy.catalogue')" variant="secondary" size="sm">See everything</Button>
            </div>

            <EmptyState
                v-if="!featured.length"
                title="No courses published yet"
                description="The first ones are being written. Nothing here is a placeholder — when a course appears, it is finished."
            />

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <CourseCard v-for="course in featured" :key="course.slug" :course="course" />
            </div>
        </section>

        <section v-if="newest.length" class="mt-10" aria-labelledby="newest-heading">
            <h2 id="newest-heading" class="mb-4 text-xl">Just added</h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <CourseCard v-for="course in newest" :key="course.slug" :course="course" />
            </div>
        </section>
    </PublicLayout>
</template>
