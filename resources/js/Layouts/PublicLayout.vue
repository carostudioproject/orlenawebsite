<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AppHeader from '../Components/AppHeader.vue';
import AppFooter from '../Components/AppFooter.vue';
import type { Seo, SocialLinks } from '../Types/content';
import { usePublicNavigation } from '../Composables/usePublicNavigation';
const page = usePage<{ seo: Seo; site: SocialLinks }>();
usePublicNavigation();
withDefaults(defineProps<{ showWhatsApp?: boolean }>(), { showWhatsApp: true });
</script>
<template>
    <Head :title="page.props.seo.title">
        <meta head-key="description" name="description" :content="page.props.seo.description">
        <link head-key="canonical" rel="canonical" :href="page.props.seo.canonical">
        <meta head-key="og:title" property="og:title" :content="page.props.seo.title">
        <meta head-key="og:description" property="og:description" :content="page.props.seo.description">
        <meta head-key="og:url" property="og:url" :content="page.props.seo.canonical">
        <meta head-key="og:image" property="og:image" :content="page.props.seo.image">
        <meta head-key="robots" name="robots" :content="page.props.seo.indexable ? 'index,follow' : 'noindex,nofollow'">
    </Head>
    <a href="#main-content" class="skip-link">Skip to content</a>
    <AppHeader />
    <main id="main-content" tabindex="-1"><slot /></main>
    <AppFooter />
    <a v-if="showWhatsApp && page.props.site?.whatsappNumber" :href="`https://wa.me/${page.props.site.whatsappNumber}`" target="_blank" rel="noopener noreferrer" class="whatsapp-floating" aria-label="Chat with Orlena on WhatsApp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></a>
</template>
