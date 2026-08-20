<script setup>
/**
 * What this board is, and is not.
 *
 * Not a footnote and not dismissable. People will travel on the strength of
 * these listings, and the single most important thing the platform can tell
 * them is that it has checked nobody and is standing behind nothing. It sits
 * above the content on every page of this module for that reason.
 */
defineProps({
    notice: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="rounded-sm border-2 border-ink bg-chrome-50 p-3 dark:border-wash dark:bg-grain-800" role="note">
        <p class="stencil text-ink dark:text-wash">{{ notice.heading }}</p>
        <p v-if="!compact" class="mt-1.5 text-sm">{{ notice.body }}</p>
    </div>
</template>
