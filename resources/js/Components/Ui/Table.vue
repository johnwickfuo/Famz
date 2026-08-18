<script setup>
/**
 * One table, two shapes. At 360px each row becomes its own stacked card with
 * the column name printed as a stencil label; from `sm` up it is a real table.
 * Both come from the same markup, so screen readers get proper headers either
 * way.
 *
 * columns: [{ key, label, align?, figures?, hideOnMobile? }]
 */
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
    caption: { type: String, default: null },
});
</script>

<template>
    <div class="w-full overflow-x-auto">
        <table class="w-full border-collapse text-left">
            <caption v-if="caption" class="sr-only">{{ caption }}</caption>

            <thead class="hidden sm:table-header-group">
                <tr class="border-b-2 border-dashed border-ink dark:border-wash">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        class="stencil px-3 py-2 text-muted"
                        :class="column.align === 'right' ? 'text-right' : ''"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row[rowKey]"
                    class="block border-b-2 border-dashed border-grain-300 last:border-0 sm:table-row dark:border-grain-700"
                >
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        class="flex items-baseline justify-between gap-3 px-3 py-2 text-sm sm:table-cell sm:align-top"
                        :class="[
                            column.align === 'right' ? 'sm:text-right' : '',
                            column.figures ? 'figures' : '',
                            column.hideOnMobile ? 'hidden sm:table-cell' : '',
                        ]"
                    >
                        <span class="stencil shrink-0 text-muted sm:hidden" aria-hidden="true">
                            {{ column.label }}
                        </span>
                        <span class="min-w-0 text-right sm:text-inherit">
                            <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                                {{ row[column.key] }}
                            </slot>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <slot v-if="rows.length === 0" name="empty" />
    </div>
</template>
