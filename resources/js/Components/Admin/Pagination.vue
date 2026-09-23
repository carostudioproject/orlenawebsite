<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PageLink } from '../../Types/admin';
defineProps<{ links: PageLink[] }>();
const label = (value: string) => value.includes('Previous') ? 'Previous' : value.includes('Next') ? 'Next' : value;
</script>
<template><nav v-if="links.length > 3" aria-label="Pages" class="mt-5 flex flex-wrap gap-2"><template v-for="(item,index) in links" :key="index"><Link v-if="item.url" :href="item.url" class="admin-secondary" :class="item.active ? 'bg-almond' : ''" :aria-current="item.active ? 'page' : undefined">{{ label(item.label) }}</Link><span v-else class="px-3 py-2 text-sm opacity-40">{{ label(item.label) }}</span></template></nav></template>
