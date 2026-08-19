<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A video lesson, played in place.
 *
 * The link behind it is signed and lasts five minutes, which is shorter than
 * most of these videos. That is deliberate — a link that outlives the lesson is
 * a link worth forwarding — and it means the source WILL go stale while
 * somebody is watching: they pause to answer the door, come back, and the next
 * range request is refused.
 *
 * So a stale link is treated as an ordinary event rather than an error. When
 * the element complains, we quietly ask for a fresh one, put it back at the
 * exact second they were on, and carry on. The student never learns any of
 * this happened.
 */
const props = defineProps({
    src: { type: String, required: true },
    refreshUrl: { type: String, default: null },
    title: { type: String, default: null },
    startAt: { type: Number, default: 0 },
});

const emit = defineEmits(['position', 'ended']);

const video = ref(null);
const source = ref(props.src);
const error = ref(null);
const recovering = ref(false);

let lastReported = 0;

function onLoaded() {
    // Both on first load and after a re-mint: the element forgets where it was.
    if (props.startAt > 0 && video.value.currentTime < 1) {
        video.value.currentTime = Math.min(props.startAt, video.value.duration - 1 || props.startAt);
    }
}

function onTimeUpdate() {
    const at = Math.floor(video.value?.currentTime ?? 0);

    // Once every ten seconds. This fires four times a second otherwise, and
    // every one of those would be a request on a metered connection.
    if (at > 0 && at - lastReported >= 10) {
        lastReported = at;
        emit('position', at);
    }
}

/**
 * Swap in a fresh link without losing the reader's place.
 */
async function remint() {
    if (!props.refreshUrl || recovering.value) {
        return;
    }

    recovering.value = true;

    const at = video.value?.currentTime ?? 0;
    const wasPlaying = video.value !== null && !video.value.paused;

    try {
        const response = await fetch(props.refreshUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('refused');
        }

        source.value = (await response.json()).url;
        error.value = null;

        // Wait for the element to pick the new source up before seeking.
        await new Promise((resolve) => {
            video.value.load();
            video.value.addEventListener('loadedmetadata', resolve, { once: true });
        });

        video.value.currentTime = at;

        if (wasPlaying) {
            await video.value.play().catch(() => {});
        }
    } catch {
        error.value = 'We lost the connection to this video. Reload the page to carry on.';
    } finally {
        recovering.value = false;
    }
}

function onError() {
    remint();
}

onMounted(() => {
    lastReported = props.startAt ?? 0;
});

onBeforeUnmount(() => {
    const at = Math.floor(video.value?.currentTime ?? 0);

    if (at > 0) {
        emit('position', at);
    }
});
</script>

<template>
    <div>
        <video
            ref="video"
            :src="source"
            class="w-full rounded-sm border-2 border-ink bg-ink dark:border-wash"
            controls
            playsinline
            preload="metadata"
            controlslist="nodownload noplaybackrate"
            disablepictureinpicture
            :aria-label="title ?? undefined"
            @loadedmetadata="onLoaded"
            @timeupdate="onTimeUpdate"
            @error="onError"
            @ended="emit('ended')"
            @contextmenu.prevent
        ></video>

        <p v-if="recovering" class="mt-2 text-sm text-muted">Picking the video back up…</p>

        <p
            v-else-if="error"
            class="mt-2 rounded-sm border-2 border-cockscomb bg-cockscomb-100 p-3 text-sm dark:bg-grain-800"
        >
            {{ error }}
        </p>
    </div>
</template>
