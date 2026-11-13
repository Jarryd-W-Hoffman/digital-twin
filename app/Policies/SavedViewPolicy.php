<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedView;
use App\Models\User;

/**
 * Anyone who can see a twin saves views of it. Whoever saved a view changes
 * and deletes it; editors look after the shared ones, and only they share a
 * view with everyone or stop sharing it.
 */
final readonly class SavedViewPolicy
{
    public function update(User $user, SavedView $view): bool
    {
        return $view->author_id === $user->id
            || ($view->shared && $this->edits($user, $view));
    }

    public function delete(User $user, SavedView $view): bool
    {
        return $view->author_id === $user->id || $this->edits($user, $view);
    }

    public function share(User $user, SavedView $view): bool
    {
        return $this->edits($user, $view);
    }

    private function edits(User $user, SavedView $view): bool
    {
        return $user->roleIn($view->twin->organisation)?->canEditTwins() ?? false;
    }
}
