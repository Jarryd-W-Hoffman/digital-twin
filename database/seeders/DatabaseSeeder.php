<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\AddMember;
use App\Actions\CreateOrganisation;
use App\Actions\CreateTwin;
use App\Enums\OrganisationRole;
use App\Models\User;
use App\Support\SampleTwins;
use Illuminate\Database\Seeder;

/**
 * A working organisation to log in to: test@example.com / password owns it,
 * with an operator and a viewer to try the roles, and both sample twins.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(CreateOrganisation $organisations, AddMember $members, CreateTwin $twins, SampleTwins $samples): void
    {
        $owner = User::factory()->withoutTwoFactor()->create(['name' => 'Test User', 'email' => 'test@example.com']);
        $organisation = $organisations->handle($owner, 'Northshore Logistics');

        foreach ([OrganisationRole::Operator, OrganisationRole::Viewer] as $role) {
            $user = User::factory()->withoutTwoFactor()->create([
                'name' => $role->label().' User',
                'email' => $role->value.'@example.com',
            ]);
            $members->handle($organisation, $user->email, $role);
            $user->forceFill(['current_organisation_id' => $organisation->id])->save();
        }

        foreach ($samples->all() as $id => $name) {
            $twins->handle($organisation, $owner, $name, null, $samples->definition($id));
        }
    }
}
