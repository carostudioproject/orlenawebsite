<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
// The header and footer around order pages are server-rendered (resources/views/app.blade.php).
const page = usePage<{ notice?: string }>();
</script>
<template>
    <div class="shop-shell admin-shell bg-[#fffaf6]">
        <div class="mx-auto min-h-[55vh] max-w-6xl px-5 py-10 sm:py-14">
            <p v-if="page.props.notice" role="status" class="mb-6 rounded-xl bg-almond/50 p-4 text-sm">{{ page.props.notice }}</p>
            <slot />
        </div>
    </div>
</template>
