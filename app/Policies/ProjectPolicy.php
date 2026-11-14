<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;

/**
 * Projects group work; access follows the organisation role, as for twins.
 */
final readonly class ProjectPolicy
{
    public function create(User $user, Organisation $organisation): bool
    {
        return $user->accessIn($organisation)?->canEditTwins() ?? false;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->accessIn($project->organisation) instanceof OrganisationRole;
    }

    public function update(User $user, Project $project): bool
    {
        return $user->accessIn($project->organisation)?->canEditTwins() ?? false;
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
