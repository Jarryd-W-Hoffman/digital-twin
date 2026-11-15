<?php

declare(strict_types=1);

it('shows the landing page without links it has no address for', function (): void {
    config(['meridian.landing.source_url' => null, 'meridian.landing.hosted_url' => null]);

    $this->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('welcome')
        ->where('sourceUrl', null)
        ->where('hostedUrl', null));
});

it('links to the code and to hosting once they have addresses', function (): void {
    config([
        'meridian.landing.source_url' => 'https://github.com/example/meridian',
        'meridian.landing.hosted_url' => 'https://meridian.example.com/hosting',
    ]);

    $this->get(route('home'))->assertInertia(fn ($page) => $page
        ->where('sourceUrl', 'https://github.com/example/meridian')
        ->where('hostedUrl', 'https://meridian.example.com/hosting'));
});
