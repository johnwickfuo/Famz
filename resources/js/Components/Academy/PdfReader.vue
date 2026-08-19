<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import * as pdfjs from 'pdfjs-dist';
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import Button from '@/Components/Ui/Button.vue';

/**
 * A PDF read in the page, one page at a time.
 *
 * Drawn onto a canvas by PDF.js rather than handed to the browser's built-in
 * viewer, which comes with a download button and a print button we have no way
 * to remove. One canvas is also the kinder choice on a mid-range phone: a
 * forty-page workbook rendered all at once is forty full-size bitmaps in
 * memory over a connection that may not survive them.
 *
 * None of this is DRM. Somebody determined will photograph the screen, and the
 * watermark stamped on serve is the answer to that. The job here is to stop the
 * casual "save as, send to the group chat".
 *
 * PDF.js is pinned to an exact version in package.json rather than a caret
 * range. Later releases call Map.prototype.getOrInsertComputed, which no
 * Android WebView in the field has yet — the reader loads the document and then
 * fails on the first page, on exactly the phones most of these students are
 * holding. Before moving the pin, open a handout on an old device.
 */
const props = defineProps({
    /** A signed, short-lived URL. It will expire while the page is open. */
    src: { type: String, required: true },
    /** Where to ask for a fresh one when it does. */
    refreshUrl: { type: String, default: null },
    title: { type: String, default: null },
    /** The page they were last on, so a handout reopens where they stopped. */
    initialPage: { type: Number, default: 1 },
});

const emit = defineEmits(['update:page']);

pdfjs.GlobalWorkerOptions.workerSrc = workerSrc;

const canvas = ref(null);
const frame = ref(null);

// shallowRef: the PDF document is a big live object with its own internals.
// Making it deeply reactive would be pointless work on every render.
const document = shallowRef(null);
const renderTask = shallowRef(null);

const page = ref(Math.max(1, props.initialPage));
const pageCount = ref(0);
const zoom = ref(1);
const loading = ref(true);
const error = ref(null);

const url = ref(props.src);

const canGoBack = computed(() => page.value > 1);
const canGoOn = computed(() => page.value < pageCount.value);

/**
 * Fetch the file ourselves rather than letting PDF.js do it.
 *
 * It costs a few lines and buys the thing that matters: a 403 from an expired
 * link is something we can recognise and quietly fix, instead of a dead viewer
 * and a student who thinks the course is broken.
 */
async function fetchBytes(attempt = 0) {
    const response = await fetch(url.value, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf' },
    });

    if (response.status === 403 && attempt === 0 && props.refreshUrl) {
        const minted = await fetch(props.refreshUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (minted.ok) {
            url.value = (await minted.json()).url;

            return fetchBytes(attempt + 1);
        }
    }

    if (!response.ok) {
        throw new Error(
            response.status === 403
                ? 'This link has expired. Reload the page to carry on.'
                : 'We could not open this file.',
        );
    }

    return response.arrayBuffer();
}

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const bytes = await fetchBytes();

        document.value = await pdfjs.getDocument({
            data: bytes,
            // The file came from our own storage, so there is nothing to
            // fetch across the network mid-render.
            disableAutoFetch: true,
            isEvalSupported: false,
        }).promise;

        pageCount.value = document.value.numPages;
        page.value = Math.min(page.value, pageCount.value);

        await draw();
    } catch (exception) {
        error.value = exception.message ?? 'We could not open this file.';
    } finally {
        loading.value = false;
    }
}

async function draw() {
    if (!document.value || !canvas.value) {
        return;
    }

    // A page still painting when the reader moves on would fight the new one
    // for the same canvas.
    renderTask.value?.cancel();

    const pdfPage = await document.value.getPage(page.value);
    const natural = pdfPage.getViewport({ scale: 1 });
    const available = (frame.value?.clientWidth ?? natural.width) - 2;

    // Fit the width first, then apply whatever zoom the reader asked for.
    const scale = (available / natural.width) * zoom.value;
    const viewport = pdfPage.getViewport({ scale });

    // Retina without the ruinous memory bill: two device pixels per CSS pixel
    // is sharp, four is a crashed tab on a cheap phone.
    const ratio = Math.min(window.devicePixelRatio || 1, 2);

    canvas.value.width = Math.floor(viewport.width * ratio);
    canvas.value.height = Math.floor(viewport.height * ratio);
    canvas.value.style.width = `${Math.floor(viewport.width)}px`;
    canvas.value.style.height = `${Math.floor(viewport.height)}px`;

    const context = canvas.value.getContext('2d');

    context.setTransform(ratio, 0, 0, ratio, 0, 0);

    try {
        renderTask.value = pdfPage.render({ canvasContext: context, viewport });
        await renderTask.value.promise;
    } catch (exception) {
        // Cancelling on purpose is not a failure worth showing anybody.
        if (exception?.name !== 'RenderingCancelledException') {
            error.value = 'We could not draw this page.';
        }
    } finally {
        renderTask.value = null;
    }
}

function go(delta) {
    const next = page.value + delta;

    if (next >= 1 && next <= pageCount.value) {
        page.value = next;
        frame.value?.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function setZoom(delta) {
    zoom.value = Math.min(3, Math.max(0.6, Math.round((zoom.value + delta) * 20) / 20));
}

let resizeTimer = null;

function onResize() {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(draw, 200);
}

watch(page, (value) => {
    emit('update:page', value);
    draw();
});
watch(zoom, draw);
watch(
    () => props.src,
    (value) => {
        url.value = value;
        page.value = Math.max(1, props.initialPage);
        zoom.value = 1;
        load();
    },
);

onMounted(() => {
    window.addEventListener('resize', onResize);
    load();
});

onBeforeUnmount(() => {
    window.clearTimeout(resizeTimer);
    window.removeEventListener('resize', onResize);
    renderTask.value?.cancel();
    document.value?.destroy();
});
</script>

<template>
    <div class="rounded-sm border-2 border-ink bg-grain-100 dark:border-wash dark:bg-grain-900">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b-2 border-ink px-3 py-2 dark:border-wash">
            <div class="flex items-center gap-1">
                <Button size="sm" variant="secondary" :disabled="!canGoBack" @click="go(-1)">
                    <span aria-hidden="true">&larr;</span>
                    <span class="sr-only">Previous page</span>
                </Button>
                <Button size="sm" variant="secondary" :disabled="!canGoOn" @click="go(1)">
                    <span aria-hidden="true">&rarr;</span>
                    <span class="sr-only">Next page</span>
                </Button>
                <p class="figures ml-2 text-xs text-muted" aria-live="polite">
                    <span v-if="pageCount">Page {{ page }} of {{ pageCount }}</span>
                    <span v-else>Opening…</span>
                </p>
            </div>

            <div class="flex items-center gap-1">
                <Button size="sm" variant="secondary" :disabled="zoom <= 0.6" @click="setZoom(-0.25)">
                    <span aria-hidden="true">&minus;</span>
                    <span class="sr-only">Zoom out</span>
                </Button>
                <span class="figures w-12 text-center text-xs text-muted">{{ Math.round(zoom * 100) }}%</span>
                <Button size="sm" variant="secondary" :disabled="zoom >= 3" @click="setZoom(0.25)">
                    <span aria-hidden="true">+</span>
                    <span class="sr-only">Zoom in</span>
                </Button>
            </div>
        </div>

        <!--
            There is deliberately no download and no print button here. The
            file is course material somebody paid for; it is read in place.
        -->
        <!--
            An A4 page fitted to a 360px screen puts the body text at about six
            points. The zoom is the answer, so it is worth saying so once rather
            than leaving somebody to squint at the default.
        -->
        <p class="border-b-2 border-dashed border-grain-300 px-3 py-2 text-xs text-muted sm:hidden">
            Use + to make the text bigger, then swipe to move around the page.
        </p>

        <div ref="frame" class="max-h-[75vh] overflow-auto p-2 sm:p-3">
            <p v-if="error" class="rounded-sm border-2 border-cockscomb bg-cockscomb-100 p-3 text-sm dark:bg-grain-800">
                {{ error }}
            </p>

            <p v-else-if="loading" class="py-10 text-center text-sm text-muted">Opening {{ title ?? 'the file' }}…</p>

            <canvas
                v-show="!loading && !error"
                ref="canvas"
                class="pdf-page mx-auto block border border-grain-400 bg-white"
                :aria-label="title ? `${title}, page ${page} of ${pageCount}` : `Page ${page} of ${pageCount}`"
                role="img"
                @contextmenu.prevent
            />
        </div>
    </div>
</template>

<style scoped>
/* Printing the page would otherwise print the canvas. */
@media print {
    .pdf-page {
        display: none;
    }
}
</style>
