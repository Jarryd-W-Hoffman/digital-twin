<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateTwin;
use App\Actions\DeleteTwin;
use App\Actions\IssueGatewayTicket;
use App\Enums\ProjectAssetStatus;
use App\Http\Requests\StoreTwinRequest;
use App\Http\Requests\UpdateTwinRequest;
use App\Models\Alert;
use App\Models\AssetType;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\OpcUaWrite;
use App\Models\ProjectAsset;
use App\Models\SavedView;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use App\Support\SampleTwins;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final readonly class TwinController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();

        return Inertia::render('twins/index', [
            'twins' => $organisation->twins()
                ->with(['latestVersion:id,twin_id,number,created_at', 'project:id,name'])
                ->latest('updated_at')
                ->get()
                ->map(fn (Twin $twin): array => [
                    'id' => $twin->id,
                    'name' => $twin->name,
                    'description' => $twin->description,
                    'version' => $twin->latestVersion?->number,
                    'project' => ['id' => $twin->project->id, 'name' => $twin->project->name],
                    'updatedAt' => $twin->updated_at->toIso8601String(),
                ]),
            'canCreate' => Gate::allows('create', [Twin::class, $organisation]),
        ]);
    }

    public function create(Request $request, #[CurrentUser] User $user, SampleTwins $samples): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('create', [Twin::class, $organisation]);

        return Inertia::render('twins/create', [
            'projects' => $organisation->projects()->orderBy('name')->get(['id', 'name']),
            'project' => $request->filled('project') ? $request->string('project')->value() : null,
            'samples' => collect($samples->all())->map(fn (string $name, string $id): array => ['id' => $id, 'name' => $name])->values(),
        ]);
    }

    public function store(StoreTwinRequest $request, #[CurrentUser] User $user, CreateTwin $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('create', [Twin::class, $organisation]);

        $twin = $action->handle(
            $request->project(),
            $user,
            $request->string('name')->value(),
            $request->filled('description') ? $request->string('description')->value() : null,
            $request->definition(),
        );

        return to_route('twins.show', $twin);
    }

    public function show(Request $request, Twin $twin, #[CurrentUser] User $user, IssueGatewayTicket $tickets): Response
    {
        Gate::authorize('view', $twin);

        $latest = $twin->latestVersion()->firstOrFail();
        $canOperate = $user->can('operate', $twin);
        $online = $twin->gatewayOnline();
        $types = collect(is_array($latest->definition['types'] ?? null) ? $latest->definition['types'] : [])
            ->filter(fn (mixed $type): bool => is_array($type) && is_string($type['id'] ?? null))
            ->keyBy('id');

        return Inertia::render('twins/show', [
            'twin' => [
                'id' => $twin->id,
                'name' => $twin->name,
                'description' => $twin->description,
            ],
            'project' => ['id' => $twin->project->id, 'name' => $twin->project->name, 'topicPrefix' => $twin->project->topicPrefix(), 'opcuaServers' => $twin->project->opcUaServers()->orderBy('name')->pluck('name')->all()],
            'definition' => $latest->definition,
            'version' => $latest->number,
            'versions' => $twin->versions()->with('user:id,name')->latest('number')->limit(25)->get()
                ->map(fn (TwinVersion $version): array => [
                    'id' => $version->id,
                    'number' => $version->number,
                    'note' => $version->note,
                    'author' => $version->user?->name,
                    'createdAt' => $version->created_at->toIso8601String(),
                ]),
            // The project's library: what this twin shows, and what it could.
            'assets' => $twin->project->assets()->where('status', ProjectAssetStatus::Ready)->latest()->get()->map(fn (ProjectAsset $asset): array => [
                'id' => $asset->id,
                'kind' => $asset->kind->value,
                'name' => $asset->name,
                'originalName' => $asset->original_name,
                'bytes' => $asset->bytes,
                'files' => $asset->files,
                'url' => $asset->url(),
            ]),
            // The organisation's type library, and how each compares with this twin's type of that id.
            'types' => $twin->organisation->assetTypes()->orderBy('name')->get()->map(fn (AssetType $type): array => [
                'id' => $type->id,
                'slug' => $type->slug,
                'name' => $type->name,
                'inTwin' => match (true) {
                    ! $types->has($type->slug) => null,
                    $type->matches($types->get($type->slug)) => 'same',
                    default => 'different',
                },
            ]),
            'opcuaWrites' => [
                'allowed' => $twin->opcua_writes,
                'recent' => $twin->opcUaWrites()->with('user:id,name')->latest('written_at')->limit(20)->get()
                    ->map(fn (OpcUaWrite $write): array => [
                        'id' => $write->id,
                        'at' => $write->written_at->toIso8601String(),
                        'user' => $write->user?->name,
                        'server' => $write->server,
                        'node' => $write->node,
                        'asset' => $write->asset,
                        'metric' => $write->metric,
                        'requested' => $write->requested,
                        'setpoint' => $write->setpoint,
                        'value' => $write->value,
                        'error' => $write->error,
                    ]),
            ],
            // What the gateway says is open, for acknowledging from the scene.
            'alerts' => $twin->gateway_token_hash === null ? null : $twin->openAlerts()
                ->with('acknowledger:id,name')->latest('raised_at')->limit(100)->get()
                ->map(fn (Alert $alert): array => [
                    'id' => $alert->id,
                    'rule' => $alert->rule,
                    'asset' => $alert->asset,
                    'acknowledgedBy' => $alert->acknowledgerName(),
                    'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
                ]),
            // The issues pinned in the scene, and the one open in the Issues panel (`?issue=`).
            'issues' => fn () => $twin->issues()->with(['author:id,name', 'assignee:id,name'])->withCount('comments')
                ->latest('updated_at')->limit(500)->get()
                ->map(fn (Issue $issue): array => [
                    'id' => $issue->id,
                    'number' => $issue->number,
                    'title' => $issue->title,
                    'closed' => $issue->closed_at !== null,
                    'at' => [$issue->lon, $issue->lat, $issue->height],
                    'view' => $issue->view,
                    'author' => $issue->authorName(),
                    'assignee' => $issue->assignee?->name,
                    'mine' => $issue->assignee_id === $user->id,
                    'dueOn' => $issue->due_on?->format('Y-m-d'),
                    'overdue' => $issue->isOverdue(),
                    'comments' => $issue->comments_count,
                    'updatedAt' => $issue->updated_at->toIso8601String(),
                ]),
            'issue' => fn (): ?array => $this->issue($twin, $user, $request->query('issue')),
            // An alert to raise an issue from (`?raise=`), pinned at its asset.
            'raise' => fn (): ?array => $this->raise($twin, $request->query('raise')),
            // The views this user can see: theirs and the shared ones.
            'views' => fn () => $twin->savedViews()->with('author:id,name')
                ->where(fn (Builder $query): Builder => $query->where('shared', true)->orWhere('author_id', $user->id))
                ->orderByDesc('shared')->latest('updated_at')->limit(200)->get()
                ->map(fn (SavedView $view): array => $this->savedView($view->setRelation('twin', $twin), $twin, $user)),
            // Where to open: the view in the link (`?view=`), else the twin's opening view.
            'startView' => fn (): ?array => $this->startView($twin, $request->query('view')),
            'gateway' => $twin->gateway_token_hash === null ? null : [
                'online' => $online,
                'url' => $online ? $twin->gateway_url : null,
                'seenAt' => $twin->gateway_seen_at?->toIso8601String(),
                'mqtt' => $online ? $twin->gateway_mqtt : null,
                'opcua' => $online ? $twin->gateway_opcua : null,
                'ticket' => $online ? $tickets->handle($twin, $user, $canOperate, $user->can('update', $twin)) : null,
            ],
            'can' => [
                'update' => $user->can('update', $twin),
                'operate' => $canOperate,
                'manageTypes' => $user->can('manageTypes', $twin->organisation),
            ],
        ]);
    }

    public function update(UpdateTwinRequest $request, Twin $twin): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $twin->update([
            'name' => $request->string('name')->value(),
            'description' => $request->filled('description') ? $request->string('description')->value() : null,
        ]);

        return to_route('twins.show', $twin);
    }

    public function destroy(Twin $twin, DeleteTwin $action): RedirectResponse
    {
        Gate::authorize('delete', $twin);

        $action->handle($twin);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Twin deleted.')]);

        return to_route('projects.show', $twin->project_id);
    }

    /**
     * A saved view for the Views panel, with what the user may do with it.
     *
     * @return array<string, mixed>
     */
    private function savedView(SavedView $view, Twin $twin, User $user): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'author' => $view->author?->name,
            'mine' => $view->author_id === $user->id,
            'shared' => $view->shared,
            'opening' => $twin->opening_view_id === $view->id,
            'state' => $view->state,
            'can' => [
                'update' => $user->can('update', $view),
                'delete' => $user->can('delete', $view),
                'share' => $user->can('share', $view),
            ],
        ];
    }

    /**
     * The view a twin opens at: the one linked, shared or not, if it's one of
     * the twin's; else the twin's opening view.
     *
     * @return array{id: string, state: array<string, mixed>}|null
     */
    private function startView(Twin $twin, mixed $id): ?array
    {
        $view = (is_string($id) && Str::isUuid($id) ? $twin->savedViews()->find($id) : null) ?? $twin->openingView;

        return $view instanceof SavedView ? ['id' => $view->id, 'state' => $view->state] : null;
    }

    /**
     * An issue with its conversation, for the Issues panel.
     *
     * @return array<string, mixed>|null
     */
    private function issue(Twin $twin, User $user, mixed $id): ?array
    {
        $issue = is_string($id) && Str::isUuid($id)
            ? $twin->issues()->with(['author:id,name', 'closer:id,name', 'assignee:id,name', 'alert:id,asset,message,raised_at', 'comments' => fn (HasMany $query): HasMany => $query->with('author:id,name')->oldest()->oldest('id')])->find($id)
            : null;

        if (! $issue instanceof Issue) {
            return null;
        }

        $issue->setRelation('twin', $twin);
        $canModerate = $user->can('update', $twin);
        $canAssign = $user->can('assign', $issue);

        return [
            'id' => $issue->id,
            'number' => $issue->number,
            'title' => $issue->title,
            'at' => [$issue->lon, $issue->lat, $issue->height],
            'view' => $issue->view,
            'author' => $issue->authorName(),
            'createdAt' => $issue->created_at->toIso8601String(),
            'closedAt' => $issue->closed_at?->toIso8601String(),
            'closedBy' => $issue->closerName(),
            'assignee' => $issue->assignee === null ? null : ['id' => $issue->assignee->id, 'name' => $issue->assignee->name],
            'dueOn' => $issue->due_on?->format('Y-m-d'),
            'overdue' => $issue->isOverdue(),
            'alert' => $issue->alert === null ? null : [
                'message' => $issue->alert->message,
                'asset' => $issue->alert->asset,
                'raisedAt' => $issue->alert->raised_at->toIso8601String(),
                'url' => route('twins.alerts.index', $twin, false),
            ],
            'can' => ['assign' => $canAssign, 'close' => $user->can('close', $issue), 'delete' => $user->can('delete', $issue)],
            // Who it could be given to: the organisation's members.
            'members' => $canAssign ? User::query()->whereIn('id', $twin->organisation->memberships()->select('user_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $member): array => ['id' => $member->id, 'name' => $member->name]) : [],
            'comments' => $issue->comments->map(fn (IssueComment $comment): array => [
                'id' => $comment->id,
                'author' => $comment->authorName(),
                'body' => $comment->body,
                'photo' => $comment->photo === null ? null : route('twins.issues.comments.photo', [$twin, $issue, $comment], false),
                'createdAt' => $comment->created_at->toIso8601String(),
                'canDelete' => $canModerate || $comment->author_id === $user->id,
            ]),
        ];
    }

    /**
     * An alert of the twin to raise an issue from: what it says and the asset
     * it's on, where the issue is pinned.
     *
     * @return array{id: string, asset: string, message: string}|null
     */
    private function raise(Twin $twin, mixed $id): ?array
    {
        $alert = is_string($id) && Str::isUuid($id) ? $twin->alerts()->find($id) : null;

        return $alert instanceof Alert ? ['id' => $alert->id, 'asset' => $alert->asset, 'message' => $alert->message] : null;
    }
}
