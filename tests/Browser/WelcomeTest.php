<?php

declare(strict_types=1);

it('has welcome page', function (): void {
    $page = visit('/');

    $page->assertSee('Meridian')->assertSee('Open the sample twins');
});
