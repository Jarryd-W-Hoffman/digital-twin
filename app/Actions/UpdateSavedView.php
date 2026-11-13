<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SavedView;
use App\Models\Twin;
use Illuminate\Support\Facades\DB;

/**
 * Renames a view, shares it or stops sharing it, or saves a new look over
 * it. A view nobody else can see any more stops being the twin's opening one.
 */
final readonly class UpdateSavedView
{
    /**
     * @param  array<string, mixed>|null  $state
     */
    public function handle(SavedView $view, ?string $name = null, ?bool $shared = null, ?array $state = null): void
    {
        DB::transaction(function () use ($view, $name, $shared, $state): void {
            $view->update(array_filter([
                'name' => $name,
                'shared' => $shared,
                'state' => $state,
            ], fn (mixed $value): bool => $value !== null));

            if ($shared === false) {
                Twin::query()->where('opening_view_id', $view->id)->update(['opening_view_id' => null]);
            }
        });
    }
}
