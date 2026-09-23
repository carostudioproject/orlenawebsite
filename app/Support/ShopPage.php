<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShopPage
{
    public static function render(Request $request, string $component, string $title, array $props = []): Response
    {
        $seo = ['title' => $title.' · Orlena', 'description' => 'Pre-order Orlena untuk hari berikutnya atau setelahnya. Ketersediaan dikonfirmasi oleh tim Orlena.', 'canonical' => url()->current(), 'image' => url('/assets/images/Orlena-Logo.png'), 'indexable' => false];
        Inertia::encryptHistory();

        return Inertia::render('Shop/'.$component, [...$props, 'seo' => $seo,
            'notice' => $request->session()->get('notice'),
        ])->withViewData('seo', $seo);
    }
}
