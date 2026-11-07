<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\Project;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Twin>
 */
final class TwinFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            // A project in the twin's own organisation, so for($organisation) stays consistent.
            'project_id' => fn (array $attributes): string => Project::factory()->create(['organisation_id' => $attributes['organisation_id']])->id,
            'name' => fake()->city().' depot',
            'description' => fake()->sentence(),
        ];
    }

    public function inProject(Project $project): self
    {
        return $this->state(['organisation_id' => $project->organisation_id, 'project_id' => $project->id]);
    }
}
