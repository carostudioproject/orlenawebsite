<?php

namespace Tests;

use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Public pages are Blade views; this checks the data they rendered with.
        TestResponse::macro('assertPublicPage', function (Closure $callback) {
            /** @var TestResponse $this */
            $this->assertOk();
            $callback(new PublicPage($this->original->getData()));

            return $this;
        });
    }
}
