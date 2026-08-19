<script setup>
import { computed, defineAsyncComponent, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import ProgressBar from '@/Components/Academy/ProgressBar.vue';
import VideoLesson from '@/Components/Academy/VideoLesson.vue';

// Only fetched if somebody actually opens a handout preview. See Player.vue.
const PdfReader = defineAsyncComponent(() => import('@/Components/Academy/PdfReader.vue'));

/**
 * A course, before you own it.
 *
 * The whole curriculum is listed — every module, every lesson title, every
 * length — because a list of what you are buying is what makes the price
 * arguable. The lessons marked as previews actually play; the rest are titles.
 */
const props = defineProps({
    course: { type: Object, required: true },
    curriculum: { type: Array, default: () => [] },
    enrolment: { type: Object, default: null },
});

const openPreview = ref(null);

function togglePreview(lesson) {
    openPreview.value = openPreview.value === lesson.id ? null : lesson.id;
}

const previewCount = computed(() =>
    props.curriculum.reduce((total, module) => total + module.lessons.filter((l) => l.is_preview).length, 0),
);

const owned = computed(() => props.enrolment !== null);
</script>

<template>
    <PublicLayout :title="course.title">
        <p class="stencil text-muted">
            <Link :href="route('academy.catalogue')" class="underline-offset-4 hover:underline">Courses</Link>
            <template v-if="course.category">
                <span aria-hidden="true"> / </span>
                <Link
                    :href="route('academy.catalogue', { category: course.category_slug })"
                    class="underline-offset-4 hover:underline"
                >
                    {{ course.category }}
                </Link>
            </template>
        </p>

        <h1 class="mt-2 text-2xl sm:text-3xl">{{ course.title }}</h1>
        <p v-if="course.summary" class="mt-2 max-w-2xl text-base text-muted">{{ course.summary }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <Badge variant="neutral">{{ course.level }}</Badge>
            <Badge variant="neutral">
                {{ course.lesson_count }} lesson{{ course.lesson_count === 1 ? '' : 's' }}
            </Badge>
            <Badge v-if="course.minutes" variant="neutral">{{ course.minutes }} min</Badge>
            <Badge v-if="course.has_quiz" variant="pending">Quiz + certificate</Badge>
            <Badge v-if="course.students" variant="neutral">{{ course.students }} enrolled</Badge>
        </div>

        <hr class="seam seam-chrome my-5" />

        <div class="flex flex-col lg:flex-row lg:items-start lg:gap-6">
            <!--
                The buy panel is second in the source but first on a phone: at
                360px everything below is a long scroll, and somebody who came
                here to buy should not have to reach the bottom to do it.
            -->
            <div class="order-2 min-w-0 flex-1 space-y-5 lg:order-1">
                <img
                    v-if="course.cover"
                    :src="course.cover"
                    alt=""
                    class="w-full rounded-sm border-2 border-ink object-cover shadow-offset dark:border-wash"
                />

                <Card v-if="course.what_you_will_learn.length">
                    <template #header>
                        <h2 class="text-base">What you will be able to do</h2>
                    </template>

                    <ul class="grid gap-2 sm:grid-cols-2">
                        <li
                            v-for="(item, index) in course.what_you_will_learn"
                            :key="index"
                            class="flex items-start gap-2 text-sm"
                        >
                            <span class="mt-1 size-2 shrink-0 rounded-full bg-chrome" aria-hidden="true"></span>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </Card>

                <Card v-if="course.description">
                    <template #header>
                        <h2 class="text-base">About this course</h2>
                    </template>

                    <!-- Written by the company in the admin panel. -->
                    <div class="prose-farm text-sm" v-html="course.description"></div>
                </Card>

                <Card>
                    <template #header>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-base">The lessons</h2>
                            <p v-if="previewCount" class="figures text-xs text-muted">
                                {{ previewCount }} free to try
                            </p>
                        </div>
                    </template>

                    <div v-for="(module, index) in curriculum" :key="index" class="mb-5 last:mb-0">
                        <p class="stencil text-muted">Module {{ index + 1 }}</p>
                        <h3 class="mt-1 text-sm font-semibold">{{ module.title }}</h3>
                        <p v-if="module.summary" class="mt-0.5 text-xs text-muted">{{ module.summary }}</p>

                        <ul class="mt-2 divide-y-2 divide-dashed divide-grain-200 dark:divide-grain-700">
                            <li v-for="lesson in module.lessons" :key="lesson.id" class="py-2">
                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm leading-snug">{{ lesson.title }}</p>
                                        <p class="figures text-xs text-muted">
                                            {{ lesson.type_label }}
                                            <span v-if="lesson.duration"> · {{ lesson.duration }}</span>
                                        </p>
                                    </div>

                                    <Button
                                        v-if="lesson.is_preview && (lesson.preview_url || lesson.preview_text)"
                                        size="sm"
                                        variant="secondary"
                                        @click="togglePreview(lesson)"
                                    >
                                        {{ openPreview === lesson.id ? 'Close' : 'Preview' }}
                                    </Button>
                                    <span v-else class="stencil text-muted" aria-label="Included when you enrol">
                                        Locked
                                    </span>
                                </div>

                                <!--
                                    A preview plays in place. It uses the same
                                    reader as the course itself, so what somebody
                                    tries is what they get.
                                -->
                                <div v-if="openPreview === lesson.id" class="mt-3">
                                    <PdfReader
                                        v-if="lesson.type === 'pdf' && lesson.preview_url"
                                        :src="lesson.preview_url"
                                        :title="lesson.title"
                                    />
                                    <VideoLesson
                                        v-else-if="lesson.type === 'video' && lesson.preview_url"
                                        :src="lesson.preview_url"
                                        :title="lesson.title"
                                    />
                                    <div v-else-if="lesson.preview_text" class="prose-farm text-sm" v-html="lesson.preview_text"></div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </Card>

                <Card v-if="course.requirements.length">
                    <template #header>
                        <h2 class="text-base">What you need first</h2>
                    </template>

                    <ul class="list-disc space-y-1 pl-5 text-sm">
                        <li v-for="(item, index) in course.requirements" :key="index">{{ item }}</li>
                    </ul>
                </Card>
            </div>

            <!-- The buy panel. Sticky from `lg` up; first thing under the fold on a phone. -->
            <aside class="order-1 mb-6 lg:order-2 lg:mb-0 lg:w-80 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template v-if="owned">
                        <p class="stencil text-enamel dark:text-chrome">You own this course</p>

                        <ProgressBar class="mt-3" :percent="enrolment.progress" label="Your progress" />

                        <Button class="mt-4" :href="enrolment.player_url" block>
                            {{ enrolment.progress > 0 ? 'Carry on' : 'Start the course' }}
                        </Button>

                        <Button
                            v-if="enrolment.certificate_code"
                            class="mt-2"
                            :href="route('academy.certificate.show', enrolment.certificate_code)"
                            variant="secondary"
                            block
                        >
                            My certificate
                        </Button>
                    </template>

                    <template v-else>
                        <p class="figures text-3xl font-bold">
                            <span v-if="course.is_free" class="text-enamel dark:text-enamel-200">Free</span>
                            <span v-else>{{ course.price }}</span>
                        </p>

                        <p class="mt-1 text-xs text-muted">
                            One payment. Yours for good — there is no monthly fee and no expiry.
                        </p>

                        <Button class="mt-4" :href="route('academy.checkout', course.slug)" block>
                            {{ course.is_free ? 'Enrol free' : 'Buy this course' }}
                        </Button>

                        <hr class="seam my-4" />

                        <ul class="space-y-2 text-sm">
                            <li>{{ course.lesson_count }} lesson{{ course.lesson_count === 1 ? '' : 's' }}, read in the app</li>
                            <li v-if="course.minutes">About {{ course.minutes }} minutes of material</li>
                            <li v-if="course.has_quiz">
                                Final quiz — pass at {{ course.pass_mark }}% for the certificate
                            </li>
                            <li>Certificate an employer can check online</li>
                        </ul>

                        <p class="mt-4 rounded-sm border-2 border-grain-300 p-2 text-xs text-muted dark:border-grain-600">
                            Course material is read inside the app. There is nothing to download, and all sales are
                            final.
                        </p>
                    </template>
                </Card>
            </aside>
        </div>
    </PublicLayout>
</template>
