<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { AdminProps } from '../Types/admin';
import AppDialog from '../Components/Admin/AppDialog.vue';
import AppToast from '../Components/Admin/AppToast.vue';
const page = usePage<AdminProps>();
const can = computed(() => page.props.auth.can);
interface NavLink { label: string; href: string; icon: string; visible: boolean }
// Menu follows the account's permissions; the server authorizes every route independently.
const groups = computed(() => [
    { title: 'Operations', links: [
        { label: 'Overview', href: '/admin', icon: 'fa-gauge-high', visible: true },
        { label: 'Orders', href: '/admin/orders', icon: 'fa-receipt', visible: can.value.orders },
        { label: 'Payments', href: '/admin/payments', icon: 'fa-credit-card', visible: can.value.orders },
        { label: 'Customers', href: '/admin/customers', icon: 'fa-user-group', visible: can.value.orders },
        { label: 'Reports', href: '/admin/reports', icon: 'fa-chart-column', visible: can.value.reports },
    ] },
    { title: 'Catalog', links: [
        { label: 'Products', href: '/admin/products', icon: 'fa-cookie-bite', visible: can.value.catalog },
        { label: 'Hampers', href: '/admin/products?type=hampers', icon: 'fa-gift', visible: can.value.catalog },
        { label: 'Categories', href: '/admin/categories', icon: 'fa-tags', visible: can.value.catalog },
        { label: 'Outlets', href: '/admin/outlets', icon: 'fa-store', visible: can.value.catalog },
    ] },
    { title: 'Website', links: [
        { label: 'Website content', href: '/admin/content', icon: 'fa-pen-ruler', visible: can.value.content },
        { label: 'Blog', href: '/admin/posts', icon: 'fa-newspaper', visible: can.value.content },
    ] },
    { title: 'Settings', links: [
        { label: 'PO schedule', href: '/admin/schedule', icon: 'fa-calendar-days', visible: can.value.schedule },
        { label: 'Erzap integration', href: '/admin/integrations', icon: 'fa-plug', visible: can.value.integrations },
        { label: 'My profile', href: '/admin/profile', icon: 'fa-user-gear', visible: true },
        { label: 'Team accounts', href: '/admin/users', icon: 'fa-users', visible: can.value.users },
    ] },
].map(group => ({ ...group, links: group.links.filter(link => link.visible) as NavLink[] })).filter(group => group.links.length));
const path = computed(() => page.url.split('?')[0]);
// "Hampers" is the product list filtered by type, plus adding or editing a hamper; Products is current otherwise.
const hampers = computed(() => path.value.startsWith('/admin/products')
    && (new URLSearchParams(page.url.split('?')[1] ?? '').get('type') === 'hampers' || page.props.hamperMode === true));
const isCurrent = (href: string) => {
    if (href.includes('?')) return hampers.value;
    if (href === '/admin/products' && hampers.value) return false;
    return href === '/admin' ? path.value === href : path.value === href || path.value.startsWith(href + '/');
};
const current = computed(() => groups.value.flatMap(group => group.links).find(link => isCurrent(link.href)));
const initials = computed(() => page.props.auth.user.name.split(/\s+/).map(word => word[0]).join('').slice(0, 2).toUpperCase());

// Mobile drawer: closes on navigation and with Escape; the page behind does not scroll while open.
const drawer = ref(false);
const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') drawer.value = false; };
watch(drawer, open => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) window.addEventListener('keydown', onKey); else window.removeEventListener('keydown', onKey);
});
const removeNavigate = router.on('navigate', () => { drawer.value = false; });
// Off-screen on small screens: make the closed drawer unreachable by keyboard and screen readers.
const desktop = ref(true);
let media: MediaQueryList | undefined;
const syncMedia = () => { desktop.value = !!media?.matches; if (desktop.value) drawer.value = false; };
onMounted(() => { media = window.matchMedia('(min-width: 1024px)'); syncMedia(); media.addEventListener('change', syncMedia); });
onBeforeUnmount(() => { media?.removeEventListener('change', syncMedia); removeNavigate(); window.removeEventListener('keydown', onKey); document.body.style.overflow = ''; });
</script>
<template>
    <Head><meta head-key="robots" name="robots" content="noindex,nofollow" /></Head>
    <div class="admin-shell min-h-screen lg:pl-64">
        <div v-if="drawer" class="fixed inset-0 z-40 bg-chocolate/40 lg:hidden" aria-hidden="true" @click="drawer = false"></div>
        <aside id="admin-sidebar" class="admin-sidebar fixed inset-y-0 left-0 z-50 flex w-64 flex-col transition-transform duration-300 lg:translate-x-0" :class="drawer ? 'translate-x-0' : '-translate-x-full'" :inert="!desktop && !drawer" aria-label="Sidebar">
            <div class="flex h-16 shrink-0 items-center justify-between border-b border-chocolate/10 px-5">
                <Link href="/admin" aria-label="Dashboard overview"><img src="/assets/images/Orlena-Logo.png" alt="Orlena" class="h-8 w-auto"></Link>
                <button type="button" class="admin-icon-button inline-flex lg:hidden" aria-label="Close menu" @click="drawer = false"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <nav aria-label="Dashboard menu" class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
                <div v-for="group in groups" :key="group.title"><p class="admin-eyebrow mb-2 px-3">{{ group.title }}</p><ul class="space-y-1"><li v-for="link in group.links" :key="link.href"><Link :href="link.href" class="admin-nav-link" :aria-current="isCurrent(link.href) ? 'page' : undefined"><i class="fa-solid fa-fw" :class="link.icon" aria-hidden="true"></i>{{ link.label }}</Link></li></ul></div>
            </nav>
            <div class="border-t border-chocolate/10 p-3">
                <a href="/" target="_blank" rel="noopener" class="admin-nav-link"><i class="fa-solid fa-fw fa-arrow-up-right-from-square" aria-hidden="true"></i>View website</a>
                <Link href="/admin/logout" method="post" as="button" class="admin-nav-link w-full"><i class="fa-solid fa-fw fa-arrow-right-from-bracket" aria-hidden="true"></i>Log out</Link>
            </div>
        </aside>

        <header class="admin-topbar sticky top-0 z-30 flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
            <button type="button" class="admin-icon-button inline-flex lg:hidden" aria-label="Open menu" aria-controls="admin-sidebar" :aria-expanded="drawer" @click="drawer = true"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
            <nav aria-label="Breadcrumb" class="min-w-0 flex-1 text-sm"><ol class="flex items-center gap-2"><li class="admin-muted hidden sm:block">Dashboard</li><li class="admin-muted hidden sm:block" aria-hidden="true">/</li><li class="truncate font-bold">{{ current?.label ?? 'Dashboard' }}</li></ol></nav>
            <Link href="/admin/profile" class="admin-profile-link flex items-center gap-3 rounded-xl px-2 py-1 no-underline" aria-label="Open my profile">
                <span class="hidden text-right leading-tight sm:block"><span class="block text-sm font-bold">{{ page.props.auth.user.name }}</span><span class="admin-muted text-xs">@{{ page.props.auth.user.username }} · {{ page.props.auth.user.role_label }}</span></span>
                <span class="flex size-9 items-center justify-center rounded-full bg-almond text-sm font-bold" aria-hidden="true">{{ initials }}</span>
            </Link>
        </header>

        <main class="mx-auto max-w-350 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <slot />
        </main>
        <AppToast />
        <AppDialog />
    </div>
</template>
