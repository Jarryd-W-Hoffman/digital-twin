<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\ApiTokenAccess;
use App\Enums\AuditAction;
use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Organisation;
use App\Models\Project;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

const OPENAPI_ID = 'https://meridian.test/openapi.json';

/**
 * @return array<string, mixed>
 */
function openApi(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(public_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fails unless the decoded JSON (objects as objects) is what the description says it is.
 */
function assertDescribed(mixed $json, string $schema): void
{
    $validator = new Validator();
    $validator->setStopAtFirstError(false);
    $validator->resolver()?->registerRaw((string) file_get_contents(public_path('openapi.json')), OPENAPI_ID);
    $result = $validator->validate($json, OPENAPI_ID.'#/components/schemas/'.$schema);
    $error = $result->error();

    expect($error instanceof ValidationError ? new ErrorFormatter()->format($error) : [])->toBe([], $schema);
}

it('describes every address of the API, and only those', function (): void {
    $routes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'api.v1.'))
        ->flatMap(fn (Route $route): array => collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => mb_strtolower($method).' '.mb_substr($route->uri(), mb_strlen('api/v1')))->all())
        ->sort()->values()->all();
    /** @var array<string, array<string, mixed>> $paths */
    $paths = openApi()['paths'];
    $described = collect($paths)->flatMap(fn (array $operations, string $path): array => array_map(fn (string $method): string => $method.' '.$path, array_keys($operations)))->sort()->values()->all();

    expect($described)->toBe($routes);
});

it('lists the same choices as the control plane', function (): void {
    $schemas = openApi()['components']['schemas'];

    expect($schemas['Alert']['properties']['severity']['enum'])->toBe(array_column(AlertSeverity::cases(), 'value'))
        ->and($schemas['Token']['properties']['access']['enum'])->toBe(array_column(ApiTokenAccess::cases(), 'value'))
        ->and($schemas['AuditEvent']['properties']['area']['enum'])->toBe(array_keys(AuditAction::areas()));
});

it('is a 1.x description of the version 1 API', function (): void {
    $document = openApi();

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['info']['version'])->toStartWith('1.')
        ->and($document['servers'])->toBe([['url' => '/api/v1']]);
});

it('answers as it describes', function (): void {
    $organisation = Organisation::factory()->create();
    ApiToken::factory()->for($organisation)->token('mrd_openapi-description-check-token-0123456789')->create(['expires_at' => now()->addYear()]);
    $project = Project::factory()->for($organisation)->create();
    $twin = twinIn($organisation);
    $twin->update(['project_id' => $project->id, 'gateway_token_hash' => 'x', 'gateway_seen_at' => now()]);

    $alert = Alert::factory()->for($twin)->create();
    $issue = Issue::factory()->for($twin)->create(['due_on' => now()->addDay(), 'alert_id' => $alert->id]);
    IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/crack.png']);
    AuditEvent::factory()->for($organisation)->create(['project_id' => $project->id, 'project_name' => $project->name]);
    $get = fn (string $url): mixed => json_decode((string) $this->withToken('mrd_openapi-description-check-token-0123456789')->getJson($url)->assertOk()->getContent(), flags: JSON_THROW_ON_ERROR)->data;

    foreach ([
        [route('api.v1.token.show'), 'Token'],
        [route('api.v1.projects.show', $project), 'Project'],
        [route('api.v1.twins.show', $twin), 'Twin'],
        [route('api.v1.twins.issues.show', [$twin, $issue]), 'Issue'],
    ] as [$url, $schema]) {
        assertDescribed($get($url), $schema);
    }

    foreach ([
        [route('api.v1.projects.index'), 'Project'],
        [route('api.v1.twins.index'), 'Twin'],
        [route('api.v1.twins.alerts.index', $twin), 'Alert'],
        [route('api.v1.twins.issues.index', [$twin, 'state' => 'all']), 'Issue'],
        [route('api.v1.audit.index'), 'AuditEvent'],
    ] as [$url, $schema]) {
        $items = $get($url);
        expect($items)->not->toBeEmpty();

        foreach ($items as $item) {
            assertDescribed($item, $schema);
        }
    }
});
