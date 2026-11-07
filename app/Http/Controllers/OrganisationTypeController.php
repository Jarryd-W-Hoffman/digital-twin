<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteAssetType;
use App\Actions\SaveTypeToLibrary;
use App\Http\Requests\StoreAssetTypeRequest;
use App\Models\AssetType;
use App\Models\User;
use App\Support\TwinDefinitionValidator;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's type library: custom asset types any of its twins can
 * take a copy of.
 */
final readonly class OrganisationTypeController
{
    public function index(#[CurrentUser] User $user, TwinDefinitionValidator $validator): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('view', $organisation);

        return Inertia::render('organisation/types', [
            'organisation' => ['id' => $organisation->id, 'name' => $organisation->name],
            'types' => $organisation->assetTypes()->with('user:id,name')->orderBy('name')->get()
                ->map(function (AssetType $type) use ($validator): array {
                    $definition = $type->definition;
                    $visual = is_array($definition['visual'] ?? null) ? $definition['visual'] : [];

                    return [
                        'id' => $type->id,
                        'slug' => $type->slug,
                        'name' => $type->name,
                        'color' => $visual['color'] ?? null,
                        'shape' => $visual['shape'] ?? null,
                        'metrics' => collect(is_array($definition['metrics'] ?? null) ? $definition['metrics'] : [])
                            ->map(fn (mixed $metric): mixed => is_array($metric) ? ($metric['label'] ?? $metric['key'] ?? null) : null)
                            ->filter()->values(),
                        'states' => is_array($definition['states'] ?? null) ? count($definition['states']) : 0,
                        'reads' => $validator->type($definition)['reads'],
                        'author' => $type->user?->name,
                        'updatedAt' => $type->updated_at->toIso8601String(),
                    ];
                }),
            'canManage' => Gate::allows('manageTypes', $organisation),
        ]);
    }

    public function store(StoreAssetTypeRequest $request, #[CurrentUser] User $user, SaveTypeToLibrary $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('manageTypes', $organisation);

        $types = $request->types();
        DB::transaction(function () use ($types, $organisation, $user, $action): void {
            foreach ($types as $type) {
                $action->handle($organisation, $user, $type);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} :count type saved to the library.|[2,*] :count types saved to the library.', count($types))]);

        return to_route('organisation.types.index');
    }

    /**
     * The type as JSON, to download or paste into a definition.
     */
    public function show(AssetType $type): JsonResponse
    {
        Gate::authorize('view', $type->organisation);

        return response()->json($type->definition, headers: [
            'Content-Disposition' => sprintf('attachment; filename="%s.json"', $type->slug),
        ]);
    }

    public function destroy(AssetType $type, DeleteAssetType $action): RedirectResponse
    {
        Gate::authorize('manageTypes', $type->organisation);

        $action->handle($type);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted from the library. Twins using it keep their copy.', ['name' => $type->name])]);

        return to_route('organisation.types.index');
    }
}
