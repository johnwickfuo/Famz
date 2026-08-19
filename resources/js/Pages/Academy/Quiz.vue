<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * The final quiz.
 *
 * The page never learns which answers are right — the options arrive as text
 * and an id, and marking happens on the server. Nothing here can be read off
 * with the console open, because there is nothing here to read.
 */
const props = defineProps({
    course: { type: Object, required: true },
    quiz: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
    lastAttempt: { type: Object, default: null },
    certificate: { type: Object, default: () => ({ earned: false, outstanding: [], code: null }) },
});

// Single-choice questions hold a value, multiple-choice hold an array. The
// grader normalises both, but the inputs need the right shape to bind to.
const form = useForm({
    answers: Object.fromEntries(props.questions.map((question) => [question.id, question.multiple ? [] : null])),
});

const confirming = ref(false);

const answered = computed(
    () =>
        props.questions.filter((question) => {
            const value = form.answers[question.id];

            return Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined;
        }).length,
);

const allAnswered = computed(() => answered.value === props.questions.length);

function submit() {
    confirming.value = false;
    form.post(route('academy.quiz.submit', props.course.slug), {
        preserveScroll: false,
        onSuccess: () => form.reset(),
    });
}

function attempt() {
    // An attempt is spent whether or not every question was answered, so the
    // one thing this page owes somebody is a chance to notice the blanks.
    if (!allAnswered.value) {
        confirming.value = true;

        return;
    }

    submit();
}
</script>

<template>
    <PublicLayout :title="`${quiz.title} — ${course.title}`">
        <p class="stencil text-muted">
            <Link :href="route('academy.player', course.slug)" class="underline-offset-4 hover:underline">
                {{ course.title }}
            </Link>
        </p>

        <div class="mt-1 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <h1 class="text-2xl sm:text-3xl">{{ quiz.title }}</h1>
            <Badge v-if="quiz.passed" variant="active" dot>Passed</Badge>
        </div>

        <p v-if="quiz.description" class="mt-2 max-w-2xl text-sm text-muted">{{ quiz.description }}</p>

        <hr class="seam my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1">
                <!-- Already passed: the questions are behind a review link, not a retry. -->
                <Card v-if="quiz.passed">
                    <h2 class="text-base">You have passed this quiz</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ lastAttempt?.score }}% on {{ lastAttempt?.at }}. There is nothing more to do here.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button :href="route('academy.quiz.review', course.slug)" variant="secondary" size="sm">
                            See your answers
                        </Button>
                        <Button :href="route('academy.player', course.slug)" size="sm">Back to the course</Button>
                    </div>
                </Card>

                <Card v-else-if="!quiz.may_attempt">
                    <h2 class="text-base">No attempts left</h2>
                    <p class="mt-1 text-sm text-muted">
                        You have used every attempt at this quiz. Go back through the lessons — an administrator
                        can give you another go.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button :href="route('academy.quiz.review', course.slug)" variant="secondary" size="sm">
                            See your answers
                        </Button>
                        <Button :href="route('academy.player', course.slug)" size="sm">Back to the course</Button>
                    </div>
                </Card>

                <form v-else class="space-y-4" @submit.prevent="attempt">
                    <Card v-for="(question, index) in questions" :key="question.id">
                        <template #header>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="stencil text-muted">Question {{ index + 1 }} of {{ questions.length }}</p>
                                <p v-if="question.multiple" class="stencil text-enamel dark:text-chrome">Pick all that apply</p>
                            </div>
                        </template>

                        <fieldset>
                            <legend class="text-base leading-snug">{{ question.question }}</legend>

                            <div class="mt-3 space-y-2">
                                <label
                                    v-for="option in question.options"
                                    :key="option.id"
                                    class="flex cursor-pointer items-start gap-3 rounded-sm border-2 border-grain-300 p-3 text-sm hover:border-ink dark:border-grain-600 dark:hover:border-wash"
                                >
                                    <input
                                        v-if="question.multiple"
                                        v-model="form.answers[question.id]"
                                        type="checkbox"
                                        :value="option.id"
                                        class="mt-0.5 size-5 shrink-0 accent-enamel"
                                    />
                                    <input
                                        v-else
                                        v-model="form.answers[question.id]"
                                        type="radio"
                                        :name="`question-${question.id}`"
                                        :value="option.id"
                                        class="mt-0.5 size-5 shrink-0 accent-enamel"
                                    />
                                    <span>{{ option.text }}</span>
                                </label>
                            </div>
                        </fieldset>
                    </Card>

                    <Card>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="figures text-sm text-muted">{{ answered }} of {{ questions.length }} answered</p>
                            <Button type="submit" :loading="form.processing">Submit my answers</Button>
                        </div>

                        <div
                            v-if="confirming"
                            class="mt-3 rounded-sm border-2 border-chrome-700 bg-chrome-100 p-3 text-sm dark:bg-grain-800"
                        >
                            <p>
                                {{ questions.length - answered }} question{{
                                    questions.length - answered === 1 ? ' is' : 's are'
                                }}
                                still blank, and a blank counts as wrong. Submit anyway?
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Button size="sm" variant="danger" :loading="form.processing" @click="submit">
                                    Submit anyway
                                </Button>
                                <Button size="sm" variant="secondary" @click="confirming = false">
                                    Let me finish
                                </Button>
                            </div>
                        </div>
                    </Card>
                </form>
            </div>

            <aside class="mt-6 lg:mt-0 lg:w-72 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-base">How this works</h2>
                    </template>

                    <ul class="space-y-2 text-sm">
                        <li class="figures">Pass mark: {{ quiz.pass_mark }}%</li>
                        <li class="figures">{{ quiz.question_count }} questions</li>
                        <li v-if="quiz.attempts_left !== null" class="figures">
                            {{ quiz.attempts_left }} attempt{{ quiz.attempts_left === 1 ? '' : 's' }} left
                        </li>
                        <li v-else>Unlimited attempts</li>
                    </ul>

                    <template v-if="lastAttempt">
                        <hr class="seam my-4" />

                        <p class="stencil text-muted">Your best so far</p>
                        <p class="figures mt-1 text-2xl font-bold">{{ lastAttempt.score }}%</p>
                        <p class="figures text-xs text-muted">
                            {{ lastAttempt.correct }} of {{ lastAttempt.total }} right · {{ lastAttempt.at }}
                        </p>
                    </template>

                    <template v-if="certificate.code || certificate.earned || certificate.outstanding.length">
                        <hr class="seam my-4" />

                        <p class="stencil text-muted">Certificate</p>

                        <Button
                            v-if="certificate.code"
                            class="mt-2"
                            :href="route('academy.certificate.show', certificate.code)"
                            size="sm"
                            block
                        >
                            Open certificate
                        </Button>

                        <p v-else-if="certificate.earned" class="mt-1 text-sm">
                            Everything is done — claim it from the course page.
                        </p>

                        <ul v-else class="mt-1 list-disc space-y-1 pl-5 text-sm text-muted">
                            <li v-for="(item, index) in certificate.outstanding" :key="index">{{ item }}</li>
                        </ul>
                    </template>
                </Card>
            </aside>
        </div>
    </PublicLayout>
</template>
