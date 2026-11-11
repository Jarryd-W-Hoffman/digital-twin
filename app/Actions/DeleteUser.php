<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganisationRole;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeleteUser
{
    public function __construct(private DeleteProject $deleteProject) {}

    /**
     * Deletes the user, never leaving an organisation without an owner.
     * An organisation the user is the only member of goes with them, its
     * projects' files too; one with other members but no other owner stops
     * the deletion until someone else is made an owner.
     */
    public function handle(User $user): void
    {
        $alone = DB::transaction(function () use ($user): array {
            /** @var list<Organisation> $alone */
            $alone = [];

            $owned = $user->memberships()
                ->where('role', OrganisationRole::Owner)
                ->with('organisation')
                ->get();

            foreach ($owned as $membership) {
                $others = Membership::query()
                    ->where('organisation_id', $membership->organisation_id)
                    ->whereKeyNot($membership->id)
                    ->lockForUpdate()
                    ->get(['role']);

                if ($others->isEmpty()) {
                    $alone[] = $membership->organisation;
                } elseif ($others->doesntContain('role', OrganisationRole::Owner)) {
                    throw ValidationException::withMessages([
                        'account' => __('You are the only owner of :organisation. Make another member an owner first.', [
                            'organisation' => $membership->organisation->name,
                        ]),
                    ]);
                }
            }

            $user->delete();

            return $alone;
        });

        foreach ($alone as $organisation) {
            foreach ($organisation->projects as $project) {
                $this->deleteProject->handle($project);
            }

            $organisation->delete();
        }
    }
}
