<script setup lang="ts">
import type { Blog } from '../../Types/content';
defineProps<{ blog: Blog }>();
import PublicLayout from '../../Layouts/PublicLayout.vue';
defineOptions({ layout: PublicLayout });
</script>
<template>
<section
  class="blog-detail-page"
  v-if="blog"
>

  <header class="blog-detail-header">

    <div class="container">

      <span class="blog-detail-category">
        {{ blog.category }}
      </span>

      <h1 class="blog-detail-title">
        {{ blog.title }}
      </h1>

    </div>

  </header>

  <section class="blog-detail-featured">

    <div class="container">

      <div class="blog-detail-image">

        <img
          :src="blog.image"
          :alt="blog.title"
        />

      </div>

    </div>

  </section>

  <article class="blog-detail-article">

    <div class="container">

      <div class="blog-detail-content">

        <template
          v-for="(item, index) in blog.content" :key="index"
        >

          <p
            v-if="item.type === 'paragraph'"
            class="blog-detail-paragraph"
          >
            {{ item.text }}
          </p>

          <h2
            v-if="item.type === 'heading'"
            class="blog-detail-subheading"
          >
            {{ item.text }}
          </h2>

        </template>

      </div>

    </div>

  </article>

  <div class="blog-detail-footer">

    <div class="container">

      <a
        href="/blog"
        class="blog-detail-back-button"
      >
        Back to What's on Orlena
      </a>

    </div>

  </div>

</section>

<section
  class="blog-not-found"
  v-if="!blog"
>

  <div class="container">

    <h1>
      Blog not found
    </h1>

    <a href="/blog">
      Back to What's on Orlena
    </a>

  </div>

</section>

</template>
<style scoped>
/* =========================================
   BLOG DETAIL PAGE
========================================= */

.blog-detail-page {
  min-height: 100vh;
  background: #FFF5F0 !important;
  color: #3B0304 !important;
}


/* =========================================
   HEADER
========================================= */

.blog-detail-header {
  padding: 130px 0 75px;

  background: #F7E5DC !important;
  color: #3B0304 !important;
}

.blog-detail-back {
  display: inline-flex;
  align-items: center;

  margin-bottom: 55px;

  font-family: 'Aileron', sans-serif !important;
  font-size: 15px;
  font-weight: 600;

  text-decoration: none;

  color: #3B0304 !important;

  transition: transform 0.25s ease;
}

.blog-detail-back:hover {
  transform: translateX(-5px);
}

.blog-detail-category {
  display: block;

  margin-bottom: 18px;

  font-family: 'Aileron', sans-serif !important;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;

  color: #4D7141 !important;
}

.blog-detail-title {
  max-width: 1000px;

  margin: 0;

  font-family: 'neueRegredeMedium' !important;
  font-size: 64px;
  font-weight: normal;
  line-height: 1.08;

  color: #3B0304 !important;
}


/* =========================================
   FEATURED IMAGE
========================================= */

.blog-detail-featured {
  padding: 65px 0 80px;

  background: #FFF5F0 !important;
}

.blog-detail-image {
  width: 100%;
  max-height: 650px;

  overflow: hidden;

  border-radius: 18px;

  background: #F7DBB3 !important;
}

.blog-detail-image img {
  display: block;

  width: 100%;
  height: auto;
  max-height: 650px;

  object-fit: cover;
}


/* =========================================
   ARTICLE
========================================= */

.blog-detail-article {
  padding: 20px 0 100px;

  background: #FFF5F0 !important;
}

.blog-detail-content {
  width: 100%;
  max-width: 780px;

  margin: 0 auto;
}

.blog-detail-paragraph {
  margin: 0 0 28px;

  font-family: 'Aileron', sans-serif !important;
  font-size: 18px;
  font-weight: normal;
  line-height: 1.75;

  color: #3B0304 !important;
}

.blog-detail-subheading {
  margin: 65px 0 25px;

  font-family: 'neueRegredeMedium' !important;
  font-size: 38px;
  font-weight: normal;
  line-height: 1.15;

  color: #3B0304 !important;
}


/* =========================================
   FOOTER CTA
========================================= */

.blog-detail-footer {
  padding: 0 0 100px;

  background: #FFF5F0 !important;
}

.blog-detail-back-button {
  display: inline-flex;
  align-items: center;

  padding: 15px 25px;

  border-radius: 999px;

  background: #3B0304 !important;

  font-family: 'Aileron', sans-serif !important;
  font-size: 15px;
  font-weight: 600;

  text-decoration: none;

  color: #F7E5DC !important;

  transition:
    transform 0.25s ease,
    opacity 0.25s ease;
}

.blog-detail-back-button:hover {
  transform: translateY(-2px);
  opacity: 0.9;
}


/* =========================================
   NOT FOUND
========================================= */

.blog-not-found {
  min-height: 70vh;

  display: flex;
  align-items: center;

  background: #FFF5F0 !important;

  color: #3B0304 !important;
}

.blog-not-found h1 {
  margin-bottom: 25px;

  font-family: 'neueRegredeMedium' !important;
  color: #3B0304 !important;
}

.blog-not-found a {
  font-family: 'Aileron', sans-serif !important;
  color: #3B0304 !important;
}


/* =========================================
   TABLET
========================================= */

@media screen and (max-width: 992px) {

  .blog-detail-header {
    padding: 110px 0 65px;
  }

  .blog-detail-title {
    font-size: 52px;
  }

  .blog-detail-featured {
    padding: 50px 0 65px;
  }

  .blog-detail-content {
    max-width: 700px;
  }

  .blog-detail-paragraph {
    font-size: 17px;
  }

}


/* =========================================
   MOBILE
========================================= */

@media screen and (max-width: 768px) {

  .blog-detail-header {
    padding: 90px 20px 50px;
  }

  .blog-detail-back {
    margin-bottom: 40px;
    font-size: 14px;
  }

  .blog-detail-category {
    font-size: 12px;
    margin-bottom: 14px;
  }

  .blog-detail-title {
    font-size: 42px;
    line-height: 1.08;
  }

  .blog-detail-featured {
    padding: 35px 20px 50px;
  }

  .blog-detail-image {
    border-radius: 12px;
  }

  .blog-detail-article {
    padding: 10px 20px 70px;
  }

  .blog-detail-content {
    max-width: none;
  }

  .blog-detail-paragraph {
    font-size: 16px;
    line-height: 1.7;
    margin-bottom: 24px;
  }

  .blog-detail-subheading {
    margin: 50px 0 22px;
    font-size: 30px;
  }

  .blog-detail-footer {
    padding: 0 20px 70px;
  }

}


/* =========================================
   SMALL MOBILE
========================================= */

@media screen and (max-width: 576px) {

  .blog-detail-title {
    font-size: 36px;
  }

  .blog-detail-featured {
    padding-top: 25px;
  }

  .blog-detail-paragraph {
    font-size: 15px;
  }

  .blog-detail-subheading {
    font-size: 27px;
  }

}
</style>