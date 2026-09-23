<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SocialLinks } from '../Types/content';
// Social links are managed in the dashboard (Konten website → Sosial media & WhatsApp).
const site = computed(() => usePage<{ site: SocialLinks }>().props.site);
</script>
<template>
<footer class="or-footer" id="footer">

  <div class="container">

    <div class="or-footer-main">

      <div class="or-footer-brand">

        <a href="/" class="or-footer-logo-link">
          <img src="/assets/images/Orlena-Logo.png" alt="Orlena" class="or-footer-logo">
        </a>

        <div class="or-footer-certification">
          <img src="/assets/images/ID51110019402520824-label.png" alt="Halal" class="or-footer-halal">
        </div>

      </div>

      <div class="or-footer-column">

        <h3 class="or-footer-heading">
          Quick Links
        </h3>

        <nav class="or-footer-links">

          <a href="/about">
            About
          </a>

          <a href="/#baked-goods">
            Baked Goods
          </a>

          <a href="/#outlet">
            Location
          </a>

          <a href="/#collaboration">
            Collaboration
          </a>

        </nav>

      </div>

      <div class="or-footer-column">

        <h3 class="or-footer-heading">
          Explore
        </h3>

        <nav class="or-footer-links">

          <a href="/blog">
            What's on Orlena
          </a>

        </nav>

      </div>

      <div class="or-footer-column">

        <h3 class="or-footer-heading">
          Follow Us
        </h3>

        <div class="or-footer-links">

          <a v-if="site?.instagramUrl" :href="site.instagramUrl" target="_blank"
            rel="noopener noreferrer">
            Instagram
          </a>

          <a v-if="site?.tiktokUrl" :href="site.tiktokUrl" target="_blank"
            rel="noopener noreferrer">
            TikTok
          </a>

        </div>

      </div>

    </div>

    <div class="or-footer-bottom">

      <p>
        © 2026 Orlena
      </p>

    </div>

  </div>

</footer>

</template>
