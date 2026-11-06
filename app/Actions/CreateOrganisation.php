<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateOrganisation
{
    /**
     * Creates an organisation owned by the user and makes it their current one.
     */
    public function handle(User $user, string $name): Organisation
    {
        return DB::transaction(function () use ($user, $name): Organisation {
            $organisation = Organisation::query()->create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            ]);

            $organisation->memberships()->create([
                'user_id' => $user->id,
                'role' => OrganisationRole::Owner,
            ]);

            $user->forceFill(['current_organisation_id' => $organisation->id])->save();

            return $organisation;
        });
    }
}
