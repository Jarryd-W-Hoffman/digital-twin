<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SavedView;
use App\Models\Twin;
use Illuminate\Support\Facades\DB;

/**
 * Makes a twin open at one of its views instead of the whole site, sharing
 * the view so everyone can see it; or, given none, at the whole site again.
 */
final readonly class SetOpeningView
{
    public function handle(Twin $twin, ?SavedView $view): void
    {
        DB::transaction(function () use ($twin, $view): void {
            $view?->update(['shared' => true]);
            $twin->update(['opening_view_id' => $view?->id]);
        });
    }
}
