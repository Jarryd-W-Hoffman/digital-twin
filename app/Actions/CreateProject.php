<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organisation;
use App\Models\Project;

final readonly class CreateProject
{
    public function handle(Organisation $organisation, string $name, ?string $description = null): Project
    {
        return $organisation->projects()->create([
            'name' => $name,
            'description' => $description,
        ]);
    }
}
