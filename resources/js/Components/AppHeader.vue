<script setup lang="ts">
import { ref, nextTick, onMounted, onBeforeUnmount, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SocialLinks } from '../Types/content';
const open = ref(false);
const dialog = ref<HTMLDialogElement>();
const trigger = ref<HTMLButtonElement>();
// Pre-order ('/order') is hidden from the menu until launch; the page stays reachable by direct link.
const links = [ ['Home', '/'], ['About', '/about'], ['Baked Goods', '/#baked-goods'], ['Location', '/#outlet'], ['Collaboration', '/#collaboration'] ];
// Social links are managed in the dashboard (Konten website → Sosial media & WhatsApp).
const page = usePage<{ site: SocialLinks }>();
const instagram = computed(() => page.props.site?.instagramUrl);
const tiktok = computed(() => page.props.site?.tiktokUrl);
function close() {
    dialog.value?.close();
    open.value = false;
    document.body.style.overflow = '';
    trigger.value?.focus();
}
async function show() {
    open.value = true;
    await nextTick();
    dialog.value?.showModal();
    document.body.style.overflow = 'hidden';
}
function resize() { if (window.innerWidth >= 992 && open.value) close(); }
onMounted(() => window.addEventListener('resize', resize));
onBeforeUnmount(() => { window.removeEventListener('resize', resize); document.body.style.overflow = ''; });
</script>
<template>
    <header class="header-navbar sticky top-0 z-40 shadow-sm">
        <nav class="container flex min-h-[66px] items-center justify-between py-2" aria-label="Main navigation">
            <a href="/" class="mr-4 py-[5px]"><img src="/assets/images/Orlena-Logo.png" alt="Orlena" class="navbar-logo"></a>
            <div class="hidden flex-1 items-center min-[992px]:flex">
                <ul class="m-0 flex flex-1 list-none justify-center gap-1 p-0">
                    <li v-for="[label, href] in links" :key="href"><a :href="href" class="nav-underline block px-2 py-2 text-inherit no-underline">{{ label }}</a></li>
                </ul>
                <div class="flex items-center gap-4">
                    <a v-if="instagram" :href="instagram" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="header-social"><i class="fab fa-instagram fa-lg" aria-hidden="true"></i></a>
                    <a v-if="tiktok" :href="tiktok" target="_blank" rel="noopener noreferrer" aria-label="TikTok" class="header-social"><i class="fa-brands fa-tiktok" aria-hidden="true"></i></a>
                </div>
            </div>
            <button ref="trigger" type="button" class="or-mobile-toggle min-[992px]:!hidden" :aria-expanded="open" aria-controls="mobile-menu" aria-label="Open menu" @click="show"><span></span><span></span></button>
        </nav>
    </header>
    <dialog id="mobile-menu" ref="dialog" class="header-offcanvas m-0 h-dvh max-h-none w-screen max-w-none border-0 p-0" aria-label="Main menu" @cancel.prevent="close">
        <div class="or-mobile-header">
            <a href="/" class="or-mobile-logo" @click="close"><img src="/assets/images/Orlena-Logo.png" alt="Orlena"></a>
            <button type="button" class="or-mobile-close" aria-label="Close menu" @click="close"><span></span><span></span></button>
        </div>
        <div class="offcanvas-body">
            <nav class="menu-wrapper" aria-label="Mobile navigation">
                <ul class="navbar-nav list-none">
                    <li v-for="[label, href] in links" :key="href" class="nav-item"><a :href="href" class="nav-link" @click="close">{{ label }}</a></li>
                </ul>
            </nav>
            <div class="or-mobile-bottom">
                <a href="/blog" class="or-mobile-blog" @click="close">What's on Orlena</a>
                <div class="or-mobile-social">
                    <a v-if="instagram" :href="instagram" target="_blank" rel="noopener noreferrer">Instagram</a>
                    <a v-if="tiktok" :href="tiktok" target="_blank" rel="noopener noreferrer">TikTok</a>
                </div>
            </div>
        </div>
    </dialog>
</template>
