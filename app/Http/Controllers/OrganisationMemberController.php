<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddMember;
use App\Actions\RemoveMember;
use App\Actions\UpdateMemberRole;
use App\Enums\OrganisationRole;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final readonly class OrganisationMemberController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('view', $organisation);

        return Inertia::render('organisation/members', [
            'organisation' => ['id' => $organisation->id, 'name' => $organisation->name],
            'members' => $organisation->memberships()->with('user:id,name,email')->oldest()->get()
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->value,
                    'isYou' => $membership->user_id === $user->id,
                ]),
            'roles' => collect(OrganisationRole::cases())->map(fn (OrganisationRole $role): array => ['value' => $role->value, 'label' => $role->label()]),
            'canManage' => Gate::allows('manageMembers', $organisation),
        ]);
    }

    public function store(StoreMemberRequest $request, #[CurrentUser] User $user, AddMember $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('manageMembers', $organisation);

        $action->handle($organisation, $request->string('email')->value(), $request->role());

        return to_route('organisation.members.index');
    }

    public function update(UpdateMemberRequest $request, Membership $membership, UpdateMemberRole $action): RedirectResponse
    {
        Gate::authorize('manageMembers', $membership->organisation);

        $action->handle($membership, $request->role());

        return to_route('organisation.members.index');
    }

    public function destroy(Membership $membership, RemoveMember $action): RedirectResponse
    {
        Gate::authorize('manageMembers', $membership->organisation);

        $action->handle($membership);

        return to_route('organisation.members.index');
    }
}
