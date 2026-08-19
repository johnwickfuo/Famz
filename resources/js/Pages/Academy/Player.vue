<script setup>
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import ProgressBar from '@/Components/Academy/ProgressBar.vue';
import VideoLesson from '@/Components/Academy/VideoLesson.vue';

/*
 * PDF.js is a third of a megabyte. Loading it on a lesson that turns out to be
 * a video would be a third of a megabyte of somebody's data spent on nothing,
 * so it is fetched the moment a handout is actually opened and not before.
 */
const PdfReader = defineAsyncComponent(() => import('@/Components/Academy/PdfReader.vue'));
import { postJson } from '@/Support/http';

/**
 * The course player.
 *
 * One lesson on screen, the whole course in the sidebar. The sidebar is a
 * disclosure on a phone and a column from `lg` up: at 360px a permanent list of
 * forty lesson titles would push the actual lesson below the fold, which is the
 * one thing this page exists to show.
 */
const props = defineProps({
    course: { type: Object, required: true },
    enrolment: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
    lesson: { type: Object, default: null },
    navigation: { type: Object, default: () => ({ previous: null, next: null }) },
    quiz: { type: Object, default: null },
});

const listOpen = ref(false);
const working = ref(false);

const completed = computed(() => props.lesson?.is_completed ?? false);

/**
 * Note where they got to.
 *
 * Fire-and-forget on purpose: a failed ping is a slightly wrong resume point,
 * which is not worth interrupting a lesson to report.
 */
function remember(position) {
    if (!props.lesson) {
        return;
    }

    postJson(props.lesson.position_url, { position }).catch(() => {});
}

function toggleComplete(value = !completed.value) {
    if (!props.lesson || working.value) {
        return;
    }

    working.value = true;

    router.post(
        props.lesson.complete_url,
        { completed: value },
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = false;
            },
        },
    );
}

/** Finishing a video is finishing the lesson; nobody should have to say so twice. */
function onVideoEnded() {
    if (!completed.value) {
        toggleComplete(true);
    }
}

// Moving to another lesson closes the list on a phone, so the lesson they
// just picked is what they land on.
watch(
    () => props.lesson?.id,
    () => {
        listOpen.value = false;
    },
);

const certificate = computed(() => ({
    code: props.enrolment.certificate_code,
    earned: props.enrolment.certificate_earned,
    outstanding: props.enrolment.certificate_outstanding ?? [],
}));

function claimCertificate() {
    working.value = true;

    router.post(
        route('academy.certificate.issue', props.course.slug),
        {},
        {
            onFinish: () => {
                working.value = false;
            },
        },
    );
}
</script>

<template>
    <PublicLayout :title="course.title">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="stencil text-muted">
                    <Link :href="route('academy.course', course.slug)" class="underline-offset-4 hover:underline">
                        Course
                    </Link>
                </p>
                <h1 class="mt-1 text-2xl sm:text-3xl">{{ course.title }}</h1>
            </div>

            <div class="w-full sm:w-56">
                <ProgressBar :percent="enrolment.progress" label="Your progress" />
                <p class="figures mt-1 text-xs text-muted">
                    {{ enrolment.completed_count }} of {{ course.lesson_count }} lessons done
                </p>
            </div>
        </div>

        <hr class="seam my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <!-- The contents. A disclosure below `lg`, a column above it. -->
            <aside class="lg:w-72 lg:shrink-0">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-sm border-2 border-ink bg-surface-raised px-4 py-3 text-left shadow-offset lg:hidden dark:border-wash dark:bg-grain-900"
                    :aria-expanded="listOpen"
                    aria-controls="lesson-list"
                    @click="listOpen = !listOpen"
                >
                    <span class="font-display text-xs font-bold uppercase tracking-wider">
                        Lessons ({{ course.lesson_count }})
                    </span>
                    <span aria-hidden="true">{{ listOpen ? '−' : '+' }}</span>
                </button>

                <div id="lesson-list" class="mt-3 lg:mt-0" :class="listOpen ? 'block' : 'hidden lg:block'">
                    <Card>
                        <template #header>
                            <h2 class="text-base">In this course</h2>
                        </template>

                        <nav class="space-y-4">
                            <div v-for="module in modules" :key="module.id">
                                <p class="stencil text-muted">{{ module.title }}</p>

                                <ul class="mt-2 space-y-1">
                                    <li v-for="item in module.lessons" :key="item.id">
                                        <Link
                                            :href="item.url"
                                            class="flex items-start gap-2 rounded-sm border-2 px-2 py-2 text-sm transition-colors"
                                            :class="
                                                item.is_current
                                                    ? 'border-ink bg-chrome text-ink dark:border-wash'
                                                    : 'border-transparent hover:border-grain-300 hover:bg-grain-100 dark:hover:bg-grain-800'
                                            "
                                            :aria-current="item.is_current ? 'page' : undefined"
                                        >
                                            <!--
                                                A tick, and the word "done" for
                                                a screen reader: the mark is
                                                never the only signal.
                                            -->
                                            <span
                                                class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border-2"
                                                :class="
                                                    item.is_completed
                                                        ? 'border-enamel bg-enamel text-wash'
                                                        : 'border-grain-400'
                                                "
                                            >
                                                <span v-if="item.is_completed" class="text-[9px] leading-none">✓</span>
                                            </span>

                                            <span class="min-w-0 flex-1">
                                                <span class="block leading-snug">{{ item.title }}</span>
                                                <span class="figures block text-xs text-muted">
                                                    {{ item.type_label }}
                                                    <span v-if="item.duration"> · {{ item.duration }}</span>
                                                    <span v-if="item.is_completed" class="sr-only"> · done</span>
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                </ul>
                            </div>
                        </nav>

                        <template v-if="quiz" #footer>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold">{{ quiz.title }}</p>
                                    <p class="figures text-xs text-muted">
                                        <span v-if="quiz.passed">Passed with {{ quiz.best_score }}%</span>
                                        <span v-else-if="quiz.best_score !== null && quiz.best_score !== undefined">
                                            Best so far {{ quiz.best_score }}%
                                        </span>
                                        <span v-else>Not attempted</span>
                                    </p>
                                </div>
                                <Button :href="quiz.url" size="sm" :variant="quiz.passed ? 'secondary' : 'primary'">
                                    {{ quiz.passed ? 'Review' : 'Take quiz' }}
                                </Button>
                            </div>
                        </template>
                    </Card>
                </div>
            </aside>

            <div class="mt-6 min-w-0 flex-1 lg:mt-0">
                <EmptyState
                    v-if="!lesson"
                    title="This course has no lessons yet"
                    description="Nothing has been published into it. Check back — your access does not expire."
                />

                <div v-else class="space-y-5">
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                        <div class="min-w-0">
                            <h2 class="text-xl leading-snug">{{ lesson.title }}</h2>
                            <p class="figures mt-1 text-xs text-muted">
                                {{ lesson.type_label }}<span v-if="lesson.duration"> · {{ lesson.duration }}</span>
                            </p>
                        </div>
                        <Badge v-if="lesson.is_completed" variant="active" dot>Done</Badge>
                    </div>

                    <!-- A handout, drawn in the page. There is no download. -->
                    <PdfReader
                        v-if="lesson.type === 'pdf' && lesson.content_url"
                        :key="lesson.id"
                        :src="lesson.content_url"
                        :refresh-url="lesson.refresh_url"
                        :title="lesson.title"
                        :initial-page="Math.max(1, lesson.last_position || 1)"
                        @update:page="remember"
                    />

                    <VideoLesson
                        v-else-if="lesson.type === 'video' && lesson.content_url"
                        :key="lesson.id"
                        :src="lesson.content_url"
                        :refresh-url="lesson.refresh_url"
                        :title="lesson.title"
                        :start-at="lesson.last_position || 0"
                        @position="remember"
                        @ended="onVideoEnded"
                    />

                    <!--
                        Written by the company in the admin panel, which is the
                        only place course content comes from, so the markup it
                        carries is ours.
                    -->
                    <div v-else-if="lesson.content" class="prose-farm" v-html="lesson.content"></div>

                    <p v-else class="rounded-sm border-2 border-grain-300 p-4 text-sm text-muted">
                        There is nothing to show for this lesson yet.
                    </p>

                    <Card>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="size-5 accent-enamel"
                                    :checked="completed"
                                    :disabled="working"
                                    @change="toggleComplete($event.target.checked)"
                                />
                                <span>Mark this lesson as done</span>
                            </label>

                            <div class="flex gap-2">
                                <Button
                                    v-if="navigation.previous"
                                    :href="navigation.previous"
                                    variant="secondary"
                                    size="sm"
                                >
                                    ← Previous
                                </Button>
                                <Button v-if="navigation.next" :href="navigation.next" size="sm">Next →</Button>
                                <Button v-else-if="quiz && !quiz.passed" :href="quiz.url" size="sm">
                                    Take the quiz
                                </Button>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- The certificate: what it takes, and whether they have it. -->
                <Card class="mt-5">
                    <template #header>
                        <h2 class="text-base">Your certificate</h2>
                    </template>

                    <div v-if="certificate.code" class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm">
                            Issued. Verification code
                            <span class="figures font-semibold">{{ certificate.code }}</span
                            >.
                        </p>
                        <Button :href="route('academy.certificate.show', certificate.code)" size="sm">
                            Open certificate
                        </Button>
                    </div>

                    <div v-else-if="certificate.earned" class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm">Everything is finished. Claim it whenever you like.</p>
                        <Button size="sm" :loading="working" @click="claimCertificate">Get my certificate</Button>
                    </div>

                    <div v-else>
                        <p class="text-sm text-muted">Still to do before it can be issued:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                            <li v-for="(item, index) in certificate.outstanding" :key="index">{{ item }}</li>
                        </ul>
                    </div>
                </Card>
            </div>
        </div>
    </PublicLayout>
</template>
