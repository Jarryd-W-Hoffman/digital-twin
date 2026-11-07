<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\AddMember;
use App\Actions\CreateOrganisation;
use App\Actions\CreateProject;
use App\Actions\CreateTwin;
use App\Actions\SaveTypeToLibrary;
use App\Enums\OrganisationRole;
use App\Models\User;
use App\Support\SampleTwins;
use Illuminate\Database\Seeder;

/**
 * A working organisation to log in to: test@example.com / password owns it,
 * with an operator and a viewer to try the roles, and a project holding both
 * sample twins. Northshore's custom types start the type library.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(CreateOrganisation $organisations, AddMember $members, CreateProject $projects, CreateTwin $twins, SaveTypeToLibrary $types, SampleTwins $samples): void
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

        $project = $projects->handle($organisation, 'Pilot sites', 'The sample sites, to try the viewer, uploads and gateways on.');

        foreach ($samples->all() as $id => $name) {
            $twins->handle($project, $owner, $name, null, $samples->definition($id));
        }

        /** @var list<array<string, mixed>> $library */
        $library = $samples->definition('northshore')['types'];

        foreach ($library as $type) {
            $types->handle($organisation, $owner, $type);
        }
    }
}
