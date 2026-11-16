<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SwitchFeature;
use App\Enums\Feature;
use App\Http\Requests\UpdateOrganisationFeatureRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current organisation's optional features, which its admins and owners
 * turn on and off.
 */
final readonly class OrganisationFeatureController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('manageFeatures', $organisation);

        return Inertia::render('organisation/features', [
            'currentOrganisation' => ['id' => $organisation->id, 'name' => $organisation->name],
            'features' => collect(Feature::cases())->map(fn (Feature $feature): array => [
                'value' => $feature->value,
                'label' => $feature->label(),
                'description' => $feature->description(),
                'enabled' => $organisation->features?->contains($feature) ?? false,
                'unavailable' => $feature->unavailable(),
            ]),
        ]);
    }

    public function update(UpdateOrganisationFeatureRequest $request, Feature $feature, #[CurrentUser] User $user, SwitchFeature $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('manageFeatures', $organisation);

        $on = $request->boolean('enabled');
        $action->handle($organisation, $feature, $on);
        Inertia::flash('toast', ['type' => 'success', 'message' => $on
            ? __(':feature is on for :organisation.', ['feature' => $feature->label(), 'organisation' => $organisation->name])
            : __(':feature is off for :organisation.', ['feature' => $feature->label(), 'organisation' => $organisation->name])]);

        return to_route('organisation.features.index');
    }
}
