<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organisation;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateTwin
{
    public function __construct(private SaveTwinVersion $versions) {}

    /**
     * @param  array<string, mixed>  $definition  An already validated definition.
     */
    public function handle(Organisation $organisation, User $user, string $name, ?string $description, array $definition): Twin
    {
        return DB::transaction(function () use ($organisation, $user, $name, $description, $definition): Twin {
            $twin = $organisation->twins()->create([
                'name' => $name,
                'description' => $description,
            ]);

            $this->versions->handle($twin, $user, $definition, __('Created'));

            return $twin;
        });
    }
}
