<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Modal from '@/Components/Ui/Modal.vue';
import Textarea from '@/Components/Ui/Textarea.vue';

/**
 * A mentor's public profile.
 *
 * Everything here comes from `publicCard()` plus their own words. There is no
 * phone number, no email and no meeting link on this page for anybody, signed
 * in or not — only the METHOD they prefer, so a client can tell whether this is
 * somebody who works the way they need before paying to find out.
 */
const props = defineProps({
    mentor: { type: Object, required: true },
    packages: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
});

const page = usePage();
const signedIn = ref(page.props.auth?.user != null);

const hiring = ref(null);
const brief = ref('');
const working = ref(false);

function hire() {
    working.value = true;

    router.post(
        route('mentorship.store', hiring.value.id),
        { brief: brief.value },
        {
            onFinish: () => {
                working.value = false;
                hiring.value = null;
            },
        },
    );
}
</script>

<template>
    <PublicLayout :title="mentor.name">
        <div class="flex flex-wrap items-start gap-4">
            <img
                v-if="mentor.avatar"
                :src="mentor.avatar"
                alt=""
                class="size-20 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
            />
            <div
                v-else
                class="flex size-20 shrink-0 items-center justify-center rounded-sm border-2 border-ink bg-enamel text-2xl font-bold text-wash dark:border-wash"
                aria-hidden="true"
            >
                {{ mentor.name.charAt(0) }}
            </div>

            <div class="min-w-0 flex-1">
                <h1 class="text-2xl sm:text-3xl">{{ mentor.name }}</h1>
                <p class="mt-1 text-base text-muted">{{ mentor.headline }}</p>

                <p class="figures mt-2 text-xs text-muted">
                    <span v-if="mentor.years_experience">{{ mentor.years_experience }} years</span>
                    <span v-if="mentor.affiliation"> · {{ mentor.affiliation }}</span>
                    <span v-if="mentor.rating"> · {{ mentor.rating }}/5 from {{ mentor.reviews_count }} reviews</span>
                    <span v-if="mentor.engagements_completed">
                        · {{ mentor.engagements_completed }} engagements finished
                    </span>
                </p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-1.5">
            <Badge v-for="tag in mentor.specialisations" :key="tag.slug" variant="neutral">{{ tag.name }}</Badge>
        </div>

        <hr class="seam seam-chrome my-6" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <Card>
                    <template #header>
                        <h2 class="text-base">About</h2>
                    </template>
                    <p class="whitespace-pre-line text-sm">{{ mentor.bio }}</p>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-base">What they are good at</h2>
                    </template>
                    <p class="whitespace-pre-line text-sm">{{ mentor.strengths }}</p>
                </Card>

                <Card v-if="mentor.qualifications">
                    <template #header>
                        <h2 class="text-base">Qualifications</h2>
                    </template>
                    <p class="whitespace-pre-line text-sm">{{ mentor.qualifications }}</p>
                </Card>

                <Card v-if="reviews.length">
                    <template #header>
                        <h2 class="text-base">What clients said</h2>
                    </template>

                    <ul class="space-y-4">
                        <li
                            v-for="(review, index) in reviews"
                            :key="index"
                            class="border-b border-dashed border-grain-300 pb-4 last:border-0 last:pb-0 dark:border-grain-700"
                        >
                            <p class="figures text-sm font-semibold">
                                {{ review.rating }}/5
                                <span class="ml-2 font-normal text-muted">{{ review.by }} · {{ review.at }}</span>
                            </p>
                            <p v-if="review.comment" class="mt-1 text-sm">{{ review.comment }}</p>
                        </li>
                    </ul>
                </Card>
            </div>

            <aside class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card>
                    <template #header>
                        <h2 class="text-base">How to work with them</h2>
                    </template>

                    <ul class="space-y-4">
                        <li
                            v-for="pkg in packages"
                            :key="pkg.id"
                            class="rounded-sm border-2 border-grain-300 p-3 dark:border-grain-600"
                        >
                            <p class="text-sm font-semibold">{{ pkg.title }}</p>
                            <p class="figures mt-0.5 text-lg font-bold">{{ pkg.price_label }}</p>
                            <p class="mt-1 text-xs text-muted">{{ pkg.description }}</p>

                            <p class="figures mt-1 text-xs text-muted">
                                <span v-if="pkg.duration">{{ pkg.duration }}</span>
                                <span v-if="pkg.sessions"> · {{ pkg.sessions }} session(s)</span>
                            </p>

                            <ul v-if="pkg.deliverables.length" class="mt-2 list-disc space-y-0.5 pl-4 text-xs">
                                <li v-for="(item, i) in pkg.deliverables" :key="i">{{ item }}</li>
                            </ul>

                            <Button
                                v-if="signedIn"
                                class="mt-3"
                                size="sm"
                                block
                                @click="hiring = pkg"
                            >
                                Choose this
                            </Button>
                            <Button v-else class="mt-3" size="sm" block :href="route('login')">
                                Sign in to hire
                            </Button>
                        </li>
                    </ul>

                    <hr class="seam my-4" />

                    <p class="stencil text-muted">How they work</p>
                    <p class="mt-1 text-sm">
                        {{ mentor.contact_method
                        }}<span v-if="mentor.accepts_in_person">, and will visit a farm</span>.
                    </p>
                    <p v-if="mentor.states_served.length" class="mt-1 text-xs text-muted">
                        Travels to: {{ mentor.states_served.join(', ') }}
                    </p>

                    <p class="mt-4 rounded-sm border-2 border-chrome-700 bg-chrome-100 p-2 text-xs dark:bg-grain-800">
                        Contact details are shown once you have paid. Your money is held until you confirm the work was
                        done.
                    </p>
                </Card>
            </aside>
        </div>

        <Modal :show="hiring !== null" :title="hiring?.title ?? ''" @close="hiring = null">
            <p class="text-sm">
                You are about to hire {{ mentor.name }} for
                <span class="figures font-semibold">{{ hiring?.price_label }}</span
                >. Nothing is charged yet — the next screen takes the payment.
            </p>

            <Textarea
                v-model="brief"
                class="mt-4"
                label="Anything they should know before you start? (optional)"
                :rows="4"
            />

            <template #footer>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="secondary" @click="hiring = null">Not yet</Button>
                    <Button :loading="working" @click="hire">Continue</Button>
                </div>
            </template>
        </Modal>
    </PublicLayout>
</template>
