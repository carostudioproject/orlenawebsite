@extends('layouts.site')

@section('content')
<div class="scope-blog-show">
<section class="blog-detail-page">

  <header class="blog-detail-header">
    <div class="container">
      <span class="blog-detail-category">
        {{ $blog['category'] ?? '' }}
      </span>
      <h1 class="blog-detail-title">
        {{ $blog['title'] }}
      </h1>
    </div>
  </header>

  <section class="blog-detail-featured">
    <div class="container">
      <div class="blog-detail-image">
        <img src="{{ $blog['image'] }}" alt="{{ $blog['title'] }}" fetchpriority="high" />
      </div>
    </div>
  </section>

  <article class="blog-detail-article">
    <div class="container">
      <div class="blog-detail-content">
        @foreach ($blog['content'] as $item)
          @if ($item['type'] === 'paragraph')
          <p class="blog-detail-paragraph">
            {{ $item['text'] }}
          </p>
          @elseif ($item['type'] === 'heading')
          <h2 class="blog-detail-subheading">
            {{ $item['text'] }}
          </h2>
          @endif
        @endforeach
      </div>
    </div>
  </article>

  <div class="blog-detail-footer">
    <div class="container">
      <a href="/blog" class="blog-detail-back-button">
        Back to What's on Orlena
      </a>
    </div>
  </div>

</section>
</div>
@endsection
