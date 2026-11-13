<?php

declare(strict_types=1);

use App\Actions\SetOpeningView;
use App\Actions\UpdateSavedView;
use App\Models\SavedView;

it('changes only what it is given', function (): void {
    [, $organisation] = member();
    $view = SavedView::factory()->for(twinIn($organisation))->create();
    $state = [...$view->state, 'colorBy' => 'power'];

    new UpdateSavedView()->handle($view, name: 'Loading bay');
    new UpdateSavedView()->handle($view, state: $state);

    expect($view->refresh()->name)->toBe('Loading bay')
        ->and($view->state['colorBy'])->toBe('power')
        ->and($view->shared)->toBeFalse();
});

it('stops a view nobody else can see from opening the twin', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->create();
    new SetOpeningView()->handle($twin, $view);

    expect($view->refresh()->shared)->toBeTrue()
        ->and($twin->refresh()->opening_view_id)->toBe($view->id);

    new UpdateSavedView()->handle($view, shared: false);

    expect($view->refresh()->shared)->toBeFalse()
        ->and($twin->refresh()->opening_view_id)->toBeNull();
});

it('opens the twin at the whole site again given no view', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    new SetOpeningView()->handle($twin, SavedView::factory()->for($twin)->create());

    new SetOpeningView()->handle($twin, null);

    expect($twin->refresh()->opening_view_id)->toBeNull();
});

it('forgets the opening view when it is deleted', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->create();
    new SetOpeningView()->handle($twin, $view);

    $view->delete();

    expect($twin->refresh()->opening_view_id)->toBeNull();
});
