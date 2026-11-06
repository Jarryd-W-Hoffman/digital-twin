<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganisationRole;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
final class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'user_id' => User::factory(),
            'role' => OrganisationRole::Viewer,
        ];
    }
}
