<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Organisation;
use App\Models\Project;

final readonly class CreateProject
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Organisation $organisation, string $name, ?string $description = null): Project
    {
        $project = $organisation->projects()->create([
            'name' => $name,
            'description' => $description,
        ]);

        $this->audit->handle(AuditAction::ProjectCreated, $project, $name);

        return $project;
    }
}
