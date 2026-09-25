@extends('layouts.site')

@section('content')
<div class="scope-blog-index">
<section class="blog-page">

  <div class="blog-page-header">
    <div class="container">
      <h1 class="blog-page-title">
        What's on Orlena
      </h1>
      <p class="blog-page-intro">
        Stories, updates, and moments from our journey.
      </p>
    </div>
  </div>

  <div class="blog-page-content">
    <div class="container">
      <div class="blog-list">
        @foreach ($blogs as $index => $blog)
        <article class="blog-page-card">
          <a href="/blog/{{ $blog['slug'] }}" class="blog-page-image">
            <img src="{{ $blog['image'] }}" alt="{{ $blog['title'] }}" @if ($index > 1) loading="lazy" @endif />
          </a>
          <div class="blog-page-card-content">
            <span class="blog-page-category">
              {{ $blog['category'] ?? '' }}
            </span>
            <h2 class="blog-page-card-title">
              {{ $blog['title'] }}
            </h2>
            <p class="blog-page-card-excerpt">
              {{ $blog['excerpt'] }}
            </p>
            <a href="/blog/{{ $blog['slug'] }}" class="blog-page-read-more">
              Read More
            </a>
          </div>
        </article>
        @endforeach
      </div>
    </div>
  </div>

</section>
</div>
@endsection
