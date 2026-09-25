@extends('layouts.site')

@section('content')
@php
    // Same rule as the original card excerpt: at most 100 characters, then "...".
    $truncate = fn (string $text, int $max = 100) => mb_strlen($text) <= $max ? $text : trim(mb_substr($text, 0, $max)).'...';
@endphp
<div id="body"><h1 class="sr-only">Orlena</h1>
  <div class="w-full p-0">
    <div class="w-full">
      <div class="splide" id="main-slider">
        <div class="splide__track">
          <ul class="splide__list">
            @foreach ($hero as $index => $slide)
            <li class="splide__slide slide-img">
              <div class="slide-wrapper">
                <img src="{{ $slide['image'] }}" alt="{{ $slide['alt'] }}" @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif />
              </div>
            </li>
            @endforeach
          </ul>
        </div>
      </div>
    </div>
  </div>

  <section class="brand-mission-section" id="brand-mission">
    <div class="container">
      <div class="brand-mission-content">
        <h2 class="brand-mission-title">
          {{ $home['missionTitle'] }}
        </h2>
        <p class="brand-mission-description">
          {{ $home['missionDescription'] }}
        </p>
      </div>
    </div>
  </section>

  <section class="baked-goods-section" id="baked-goods">
    <div class="container">
      <div class="baked-goods-header">
        <h2 class="baked-goods-title">{{ $home['bakedGoodsTitle'] }}</h2>
      </div>
      <div class="splide" id="baked-goods-slider">
        <div class="splide__track">
          <ul class="splide__list">
            @foreach ($bakedGoods as $item)
            <li class="splide__slide">
              <div class="baked-category-card">
                <div class="baked-category-image">
                  <img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" loading="lazy">
                </div>
                <div class="baked-category-content">
                  <h3>{{ $item['name'] }}</h3>
                </div>
              </div>
            </li>
            @endforeach
          </ul>
        </div>
      </div>
    </div>
  </section>

  <section class="home-outlets-section" id="outlet">
    <div class="container">
      <div class="home-outlets-header">
        <h2 class="home-outlets-title">
          {{ $home['outletsTitle'] }}
        </h2>
      </div>
      <div id="outlet-slider" class="splide home-outlets-slider" aria-label="Orlena Outlets">
        <div class="splide__track">
          <ul class="splide__list">
            @foreach ($outlets as $outlet)
            <li class="splide__slide">
              <article class="home-outlet-card">
                <div class="home-outlet-image">
                  <img src="{{ $outlet['image'] }}" alt="{{ $outlet['alt'] }}" loading="lazy">
                </div>
                <div class="home-outlet-content">
                  <h3>
                    {{ $outlet['name'] }}
                  </h3>
                  <p>
                    {{ $outlet['address'] }}
                  </p>
                  @if ($outlet['mapsUrl'])
                  <a href="{{ $outlet['mapsUrl'] }}" target="_blank" rel="noopener noreferrer" class="home-outlet-map">
                    View on Maps
                  </a>
                  @endif
                </div>
              </article>
            </li>
            @endforeach
          </ul>
        </div>
      </div>
    </div>
  </section>

  <section class="brand-collaboration-section" id="collaboration">
    <div class="container">
      <div class="brand-collaboration-header">
        <h2 class="brand-collaboration-title">
          {{ $home['collaborationTitle'] }}
        </h2>
      </div>
      <div id="collaboration-slider" class="splide brand-collaboration-slider" aria-label="Orlena Brand Collaboration">
        <div class="splide__track">
          <ul class="splide__list">
            @foreach ($brandCollaborations as $collaboration)
            <li class="splide__slide">
              <article class="brand-collaboration-card">
                <div class="brand-collaboration-image">
                  <img src="{{ $collaboration['image'] }}" alt="Orlena x {{ $collaboration['name'] }}" loading="lazy">
                </div>
                <div class="brand-collaboration-info">
                  <span class="brand-collaboration-name">
                    Orlena x {{ $collaboration['name'] }}
                  </span>
                  <h3 class="brand-collaboration-headline">
                    {{ $collaboration['headline'] }}
                  </h3>
                </div>
              </article>
            </li>
            @endforeach
          </ul>
        </div>
      </div>
    </div>
  </section>

  <section class="blog-section" id="blog">
    <div class="container">
      <div class="blog-header">
        <div class="blog-heading">
          <h2 class="blog-title">
            {{ $home['blogTitle'] }}
          </h2>
        </div>
        <div class="blog-intro">
          <p>
            {{ $home['blogSubtitle'] }}
          </p>
        </div>
      </div>

      <div class="splide blog-slider" id="blog-slider">
        <div class="splide__track">
          <ul class="splide__list">
            @foreach ($blogs as $blog)
            <li class="splide__slide">
              <article class="blog-card">
                <a href="/blog" class="blog-image-wrapper">
                  <img src="{{ $blog['image'] }}" alt="{{ $blog['title'] }}" class="blog-image" loading="lazy" />
                </a>
                <div class="blog-card-content">
                  <span class="blog-category">
                    {{ $blog['category'] ?? '' }}
                  </span>
                  <h3 class="blog-card-title">
                    {{ $blog['title'] }}
                  </h3>
                  <p class="blog-card-excerpt">
                    {{ $truncate($blog['excerpt'], 100) }}
                  </p>
                  <a href="/blog/{{ $blog['slug'] }}" class="blog-read-more">
                    Read More
                  </a>
                </div>
              </article>
            </li>
            @endforeach
          </ul>
        </div>
      </div>

      <div class="blog-show-more">
        <a href="/blog" class="blog-show-more-btn">
          Show More
        </a>
      </div>
    </div>
  </section>
</div>
@endsection
