<script setup>
import { computed } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import CourseCard from '@/Components/Academy/CourseCard.vue';

const props = defineProps({
    courses: { type: Array, default: () => [] },
});

const inProgress = computed(() => props.courses.filter((course) => !course.completed));
const finished = computed(() => props.courses.filter((course) => course.completed));
</script>

<template>
    <PublicLayout title="My courses">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="stencil mb-1 text-enamel dark:text-chrome">Training</p>
                <h1 class="text-2xl sm:text-3xl">My courses</h1>
            </div>
            <Button :href="route('academy.catalogue')" variant="secondary" size="sm">Find another course</Button>
        </div>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!courses.length"
            title="You have not enrolled on anything yet"
            description="Buy a course once and it stays on this shelf. Nothing expires."
        >
            <template #action>
                <Button :href="route('academy.catalogue')">Browse courses</Button>
            </template>
        </EmptyState>

        <template v-else>
            <section v-if="inProgress.length" aria-labelledby="going-heading">
                <h2 id="going-heading" class="mb-4 text-xl">Still going</h2>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CourseCard v-for="course in inProgress" :key="course.slug" :course="course" owned />
                </div>
            </section>

            <section v-if="finished.length" class="mt-10" aria-labelledby="finished-heading">
                <h2 id="finished-heading" class="mb-4 text-xl">Finished</h2>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="course in finished" :key="course.slug" class="space-y-2">
                        <CourseCard :course="course" owned />

                        <Button
                            v-if="course.certificate_code"
                            :href="route('academy.certificate.show', course.certificate_code)"
                            variant="secondary"
                            size="sm"
                            block
                        >
                            My certificate
                        </Button>
                    </div>
                </div>
            </section>
        </template>
    </PublicLayout>
</template>
