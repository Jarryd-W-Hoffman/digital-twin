<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteProject
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Deletes the project with its twins, their versions and its library,
     * then the library's files.
     */
    public function handle(Project $project): void
    {
        /** @var list<string> $directories */
        $directories = $project->assets()->pluck('directory')->all();

        $project->delete();

        $this->audit->handle(AuditAction::ProjectDeleted, $project, $project->name);

        $disk = Storage::disk(config()->string('meridian.assets_disk'));

        foreach ($directories as $directory) {
            $disk->deleteDirectory($directory);
        }

        $disk->deleteDirectory($project->storagePrefix());
    }
}
