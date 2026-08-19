<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * The student's own certificate.
 *
 * Everything named here — the issuing company, its RC number — comes off the
 * certificate record, which froze those details the day it was awarded. The
 * company can be renamed tomorrow and this page will still say what the
 * student's PDF says.
 *
 * The PDF is a genuine download, unlike every other file in the academy: this
 * one is theirs, and a certificate you cannot send to an employer is not a
 * certificate.
 */
defineProps({
    certificate: { type: Object, required: true },
    pdfUrl: { type: String, required: true },
    verifyUrl: { type: String, required: true },
});
</script>

<template>
    <PublicLayout title="Your certificate">
        <p class="stencil text-muted">Training</p>
        <h1 class="mt-1 text-2xl sm:text-3xl">Certificate of completion</h1>

        <hr class="seam seam-chrome my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <Card class="min-w-0 flex-1">
                <template #header>
                    <p class="stencil text-muted">This is to certify that</p>
                </template>

                <p class="font-display text-2xl font-bold leading-tight sm:text-3xl">{{ certificate.holder }}</p>

                <p class="stencil mt-4 text-muted">has completed the course</p>
                <p class="mt-1 text-lg">{{ certificate.course }}</p>

                <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="stencil text-muted">Issued on</dt>
                        <dd class="figures mt-0.5 text-sm">{{ certificate.issued_on }}</dd>
                    </div>
                    <div>
                        <dt class="stencil text-muted">Verification code</dt>
                        <dd class="figures mt-0.5 text-sm font-semibold">{{ certificate.code }}</dd>
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
            </Card>

            <aside class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card>
                    <template #header>
                        <h2 class="text-base">Keep it, send it, prove it</h2>
                    </template>

                    <!-- A real anchor: the browser fetches the PDF itself. -->
                    <Button as="a" :href="pdfUrl" block>Download the PDF</Button>

                    <hr class="seam my-4" />

                    <p class="text-sm">
                        Anyone can check this certificate is real at the address below — no account needed.
                    </p>

                    <p class="figures mt-2 break-all rounded-sm border-2 border-grain-300 p-2 text-xs dark:border-grain-600">
                        {{ verifyUrl }}
                    </p>

                    <Button class="mt-3" :href="verifyUrl" variant="secondary" size="sm" block>
                        Open the check page
                    </Button>
                </Card>
            </aside>
        </div>
    </PublicLayout>
</template>
