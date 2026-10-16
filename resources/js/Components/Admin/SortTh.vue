<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface TableSortState { sort: string | null; dir: 'asc' | 'desc' | null }
// A sortable column header: ascending, then descending, then the table's default order. Filters in the URL stay.
const props = defineProps<{ column: string; label: string; sort: TableSortState }>();
const active = computed(() => props.sort.sort === props.column);
function next() {
    const params = new URLSearchParams(window.location.search);
    params.delete('page');
    if (!active.value) { params.set('sort', props.column); params.set('dir', 'asc'); }
    else if (props.sort.dir === 'asc') params.set('dir', 'desc');
    else { params.delete('sort'); params.delete('dir'); }
    router.get(window.location.pathname, Object.fromEntries(params), { preserveState: true, preserveScroll: true, replace: true });
}
</script>
<template>
    <th :aria-sort="active ? (sort.dir === 'desc' ? 'descending' : 'ascending') : 'none'">
        <button type="button" class="admin-sort" :class="{ 'is-active': active }" @click="next">
            {{ label }}<i class="fa-solid" :class="active ? (sort.dir === 'desc' ? 'fa-sort-down' : 'fa-sort-up') : 'fa-sort'" aria-hidden="true"></i>
        </button>
    </th>
</template>
