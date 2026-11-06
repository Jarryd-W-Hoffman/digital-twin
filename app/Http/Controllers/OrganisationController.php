<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateOrganisation;
use App\Actions\SwitchOrganisation;
use App\Http\Requests\StoreOrganisationRequest;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class OrganisationController
{
    public function store(StoreOrganisationRequest $request, #[CurrentUser] User $user, CreateOrganisation $action): RedirectResponse
    {
        $action->handle($user, $request->string('name')->value());

        return to_route('projects.index');
    }

    /**
     * Makes the organisation the user's current one.
     */
    public function update(Organisation $organisation, #[CurrentUser] User $user, SwitchOrganisation $action): RedirectResponse
    {
        $action->handle($user, $organisation);

        return to_route('projects.index');
    }
}
