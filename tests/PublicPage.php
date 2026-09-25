<?php

namespace Tests;

use Closure;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Assertions on the data a server-rendered public page (Blade view) received, with the same
 * where/has/missing style the Inertia page assertions use. Keys use dot notation, e.g. "blogs.0.slug".
 */
class PublicPage
{
    public function __construct(private array $data) {}

    public function where(string $key, mixed $expected): static
    {
        PHPUnit::assertTrue(Arr::has($this->data, $key), "Missing view data [{$key}].");
        $actual = Arr::get($this->data, $key);
        if ($expected instanceof Closure) {
            PHPUnit::assertTrue((bool) $expected($actual), "View data [{$key}] did not pass the check.");
        } else {
            PHPUnit::assertEquals($expected, $actual, "View data [{$key}] does not match.");
        }

        return $this;
    }

    public function has(string $key, ?int $count = null): static
    {
        PHPUnit::assertTrue(Arr::has($this->data, $key), "Missing view data [{$key}].");
        if ($count !== null) {
            PHPUnit::assertCount($count, Arr::get($this->data, $key), "View data [{$key}] has the wrong number of items.");
        }

        return $this;
    }

    public function missing(string $key): static
    {
        PHPUnit::assertFalse(Arr::has($this->data, $key), "Unexpected view data [{$key}].");

        return $this;
    }
}
