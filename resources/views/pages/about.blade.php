@extends('layouts.site')

@section('content')
<div class="scope-about">
<div class="about-page">

  <section class="about-intro">
    <div class="container">
      <div class="about-intro-inner">
        <h1 class="about-title">
          {{ $about['title'] }}
        </h1>
        <p class="about-description">
          {{ $about['description'] }}
        </p>
      </div>
    </div>
  </section>

  <section class="about-story">
    <div class="container">
      <div class="about-story-layout">
        <div class="about-story-heading">
          <h2 class="about-story-title">
            <span>{{ $about['storyTitleFirst'] }}</span>
            <span>{{ $about['storyTitleSecond'] }}</span>
          </h2>
        </div>
        <div class="about-story-content">
          <p class="about-story-lead">
            {{ $about['storyLead'] }}
          </p>
          @foreach ($about['storyBody'] as $paragraph)
          <p>
            {{ $paragraph }}
          </p>
          @endforeach
          <p class="about-story-closing">
            {{ $about['storyClosing'] }}
          </p>
        </div>
      </div>
    </div>
  </section>

  <section class="about-stores" id="outlets">
    <div class="container">
      <div class="about-stores-header">
        <h2 class="about-section-title">
          {{ $about['outletsTitle'] }}
        </h2>
      </div>
      <div id="about-outlet-slider" class="splide about-outlet-slider" aria-label="Orlena Outlets">
        <div class="splide__track">
          <ul class="splide__list">
            @foreach ($outlets as $outlet)
            <li class="splide__slide">
              <article class="about-outlet-card">
                <div class="about-outlet-image">
                  <img src="{{ $outlet['image'] }}" alt="{{ $outlet['alt'] }}" loading="lazy">
                </div>
                <div class="about-outlet-info">
                  <h3>
                    {{ $outlet['name'] }}
                  </h3>
                  <p>
                    {{ $outlet['address'] }}
                  </p>
                  @if ($outlet['mapsUrl'])
                  <a href="{{ $outlet['mapsUrl'] }}" target="_blank" rel="noopener noreferrer" class="about-outlet-map">
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

</div>
</div>
@endsection
