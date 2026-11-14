<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\SsoConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoConnection>
 */
final class SsoConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'issuer' => 'https://93.184.216.34/realms/harbour',
            'client_id' => 'meridian',
            'client_secret' => 'idp-secret',
            'auto_join' => false,
            'default_role' => OrganisationRole::Viewer,
        ];
    }

    /**
     * Signs in people from these email domains.
     *
     * @param  list<string>  $domains
     */
    public function domains(array $domains = ['harbour.example']): self
    {
        return $this->afterCreating(function (SsoConnection $connection) use ($domains): void {
            foreach ($domains as $domain) {
                $connection->domains()->create(['domain' => $domain]);
            }
        });
    }

    /**
     * Adds people it signs in for the first time as members.
     */
    public function autoJoin(OrganisationRole $role = OrganisationRole::Viewer): self
    {
        return $this->state(['auto_join' => true, 'default_role' => $role]);
    }
}
