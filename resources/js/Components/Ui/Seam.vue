<script setup>
/** The chain stitch that closes a feed sack — this site's only divider. */
defineProps({
    tone: { type: String, default: 'default' },
});
</script>

<template>
    <hr
        class="seam"
        :class="{
            'seam-chrome': tone === 'chrome',
            'seam-cockscomb': tone === 'cockscomb',
        }"
    />
</template>
