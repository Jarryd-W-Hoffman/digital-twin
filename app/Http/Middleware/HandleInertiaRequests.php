<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'organisation' => fn (): ?array => $this->organisation($user),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{current: array{id: string, name: string, role: string, features: list<string>}|null, all: list<array{id: string, name: string}>}|null
     */
    private function organisation(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $memberships = $user->memberships()->with('organisation:id,name,features')->get();
        $current = $memberships->firstWhere('organisation_id', $user->current_organisation_id);

        return [
            'current' => $current instanceof Membership ? [
                'id' => $current->organisation->id,
                'name' => $current->organisation->name,
                'role' => $current->role->value,
                'features' => $this->features($current->organisation),
            ] : null,
            'all' => array_values($memberships->map(fn (Membership $membership): array => [
                'id' => $membership->organisation->id,
                'name' => $membership->organisation->name,
            ])->all()),
        ];
    }

    /**
     * The optional features on for an organisation.
     *
     * @return list<string>
     */
    private function features(Organisation $organisation): array
    {
        $on = [];

        foreach (Feature::cases() as $feature) {
            if ($organisation->hasFeature($feature)) {
                $on[] = $feature->value;
            }
        }

        return $on;
    }
}
