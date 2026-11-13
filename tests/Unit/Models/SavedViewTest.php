<?php

declare(strict_types=1);

use App\Models\SavedView;
use App\Models\User;

it('belongs to a twin and its author, and can be where the twin opens', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->shared()->create();
    $twin->update(['opening_view_id' => $view->id]);

    expect($view->twin->is($twin))->toBeTrue()
        ->and($view->author)->toBeInstanceOf(User::class)
        ->and($view->shared)->toBeTrue()
        ->and($view->refresh()->state['camera']['heading'])->toEqual(30)
        ->and($twin->savedViews->sole()->is($view))->toBeTrue()
        ->and($twin->openingView?->is($view))->toBeTrue();
});

it('is kept when its author leaves', function (): void {
    [, $organisation] = member();
    $view = SavedView::factory()->for(twinIn($organisation))->create();

    $view->author?->delete();

    expect($view->refresh()->author_id)->toBeNull();
});
