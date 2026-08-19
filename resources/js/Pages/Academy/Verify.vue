<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * The public check.
 *
 * Aimed at an employer holding a printed certificate, so it answers one
 * question and stops: is this real, and what does it say? It deliberately shows
 * nothing about the holder beyond the name already printed on the paper in
 * their hand — this is not a lookup service for people's details.
 */
defineProps({
    code: { type: String, required: true },
    certificate: { type: Object, default: null },
});
</script>

<template>
    <PublicLayout title="Check a certificate">
        <p class="stencil text-muted">Certificate check</p>
        <h1 class="figures mt-1 text-2xl sm:text-3xl">{{ code }}</h1>

        <hr class="seam my-5" />

        <Card class="max-w-2xl">
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base">Result</h2>

                    <Badge v-if="certificate && certificate.is_valid" variant="active" dot>Genuine</Badge>
                    <Badge v-else-if="certificate" variant="danger" dot>Withdrawn</Badge>
                    <Badge v-else variant="danger" dot>Not found</Badge>
                </div>
            </template>

            <template v-if="certificate">
                <p v-if="certificate.is_valid" class="text-sm">
                    This certificate was issued by {{ certificate.issuer }} and is genuine.
                </p>
                <p v-else class="text-sm">
                    This certificate was issued by {{ certificate.issuer }} but has since been withdrawn.
                    <span v-if="certificate.revoked_reason"> Reason given: {{ certificate.revoked_reason }}</span>
                </p>

                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="stencil text-muted">Awarded to</dt>
                        <dd class="mt-0.5 text-base font-semibold">{{ certificate.holder }}</dd>
                    </div>
                    <div>
                        <dt class="stencil text-muted">For the course</dt>
                        <dd class="mt-0.5 text-sm">{{ certificate.course }}</dd>
                    </div>
                    <div>
                        <dt class="stencil text-muted">Issued on</dt>
                        <dd class="figures mt-0.5 text-sm">{{ certificate.issued_on }}</dd>
                    </div>
                    <div v-if="certificate.score !== null">
                        <dt class="stencil text-muted">Final assessment</dt>
                        <dd class="figures mt-0.5 text-sm">{{ certificate.score }}%</dd>
                    </div>
                    <div>
                        <dt class="stencil text-muted">Issued by</dt>
                        <dd class="mt-0.5 text-sm">
                            {{ certificate.issuer }}
                            <span v-if="certificate.issuer_rc" class="figures text-muted">
                                · RC {{ certificate.issuer_rc }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </template>

            <template v-else>
                <p class="text-sm">
                    No certificate carries the code <span class="figures font-semibold">{{ code }}</span
                    >. Check the code against the printed copy — the letter O and the figure 0 are easy to swap.
                </p>
            </template>

            <template #footer>
                <Button :href="route('academy.home')" variant="secondary" size="sm">See the courses</Button>
            </template>
        </Card>
    </PublicLayout>
</template>
