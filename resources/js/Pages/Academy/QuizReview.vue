<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * What you got right, and why.
 *
 * Shown after any attempt, passed or failed. A quiz that only ever says "58%"
 * is a gate; this page is the part that makes it a lesson.
 */
defineProps({
    course: { type: Object, required: true },
    attempt: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
    mayRetry: { type: Boolean, default: false },
});
</script>

<template>
    <PublicLayout :title="`Your answers — ${course.title}`">
        <p class="stencil text-muted">
            <Link :href="route('academy.player', course.slug)" class="underline-offset-4 hover:underline">
                {{ course.title }}
            </Link>
        </p>

        <div class="mt-1 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <h1 class="text-2xl sm:text-3xl">Your answers</h1>
            <Badge :variant="attempt.passed ? 'active' : 'danger'" dot>
                {{ attempt.passed ? 'Passed' : 'Not passed' }}
            </Badge>
        </div>

        <p class="figures mt-2 text-sm text-muted">
            {{ attempt.score }}% · {{ attempt.correct }} of {{ attempt.total }} right · {{ attempt.at }}
        </p>

        <hr class="seam my-5" />

        <div class="space-y-4">
            <Card v-for="(question, index) in questions" :key="index">
                <template #header>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="stencil text-muted">Question {{ index + 1 }}</p>
                        <Badge :variant="question.was_right ? 'active' : 'danger'" size="sm" dot>
                            {{ question.was_right ? 'Right' : 'Wrong' }}
                        </Badge>
                    </div>
                </template>

                <p class="text-base leading-snug">{{ question.question }}</p>

                <ul class="mt-3 space-y-2">
                    <li
                        v-for="(option, optionIndex) in question.options"
                        :key="optionIndex"
                        class="rounded-sm border-2 p-3 text-sm"
                        :class="
                            option.is_correct
                                ? 'border-enamel bg-enamel-50 dark:bg-grain-800'
                                : option.was_chosen
                                  ? 'border-cockscomb bg-cockscomb-50 dark:bg-grain-800'
                                  : 'border-grain-300 dark:border-grain-600'
                        "
                    >
                        <span>{{ option.text }}</span>

                        <!-- Words, not just colour: these two facts are the whole page. -->
                        <span v-if="option.is_correct" class="stencil ml-2 text-enamel dark:text-enamel-200">
                            Correct answer
                        </span>
                        <span
                            v-if="option.was_chosen"
                            class="stencil ml-2"
                            :class="option.is_correct ? 'text-enamel dark:text-enamel-200' : 'text-cockscomb'"
                        >
                            You picked this
                        </span>
                    </li>
                </ul>

                <p
                    v-if="question.explanation"
                    class="mt-3 rounded-sm border-2 border-grain-300 p-3 text-sm dark:border-grain-600"
                >
                    {{ question.explanation }}
                </p>
            </Card>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <Button v-if="mayRetry" :href="route('academy.quiz', course.slug)">Try again</Button>
            <Button :href="route('academy.player', course.slug)" variant="secondary">Back to the course</Button>
        </div>
    </PublicLayout>
</template>
