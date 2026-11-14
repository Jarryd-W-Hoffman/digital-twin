<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\IssueGatewayTicket;
use App\Actions\IssueGatewayToken;
use App\Actions\SaveTwinVersion;
use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\AuditEvent;
use App\Models\ProjectAsset;
use App\Models\SavedView;
use App\Models\Twin;
use App\Models\TwinShareLink;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

const SHARE_TOKEN = 'share-token-for-the-harbour-board-0123456';

beforeEach(fn () => Storage::fake('local'));

/**
 * A twin showing an uploaded model, shared by a link with a known token.
 *
 * @param  array<string, mixed>  $link
 * @return array{Twin, TwinShareLink, ProjectAsset}
 */
function sharedTwin(array $link = []): array
{
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();
    Storage::disk('local')->put($asset->directory.'/model.glb', 'glTF');
    resolve(AddAssetToTwin::class)->handle($twin, null, $asset);

    return [$twin, TwinShareLink::factory()->for($twin)->token(SHARE_TOKEN)->create($link), $asset];
}

it('lets admins and owners make a link, shown once, and revoke it', function (): void {
    $this->freezeSecond();
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.share-links.store', $twin), ['name' => 'Harbour board', 'expires_in_days' => 7, 'live' => false])
        ->assertRedirect()
        ->assertInertiaFlash('shareLink');
    $flashed = Inertia::getFlashed()['shareLink'];
    $link = TwinShareLink::query()->sole();

    expect($flashed['linkId'])->toBe($link->id)
        ->and($flashed['url'])->toStartWith(url('/share/'))
        ->and($link)->name->toBe('Harbour board')->live->toBeFalse()->created_by->toBe($user->id)
        ->and($link->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($link->hint)->toBe(mb_substr((string) $flashed['url'], -4))
        ->and($link->toArray())->not->toHaveKey('token_hash')
        ->and(AuditEvent::query()->where('action', AuditAction::ShareLinkCreated)->sole())
        ->subject->toBe('Harbour board')->twin_id->toBe($twin->id)
        ->details->toBe(['expiresAt' => now()->addDays(7)->toIso8601String(), 'live' => 'no']);

    $this->get(route('twins.show', $twin))->assertInertia(fn ($page) => $page
        ->where('can.share', true)
        ->where('shareLinks.0', [
            'id' => $link->id,
            'name' => 'Harbour board',
            'hint' => $link->hint,
            'live' => false,
            'createdBy' => $user->name,
            'createdAt' => now()->toIso8601String(),
            'expiresAt' => now()->addDays(7)->toIso8601String(),
            'expired' => false,
            'lastViewedAt' => null,
        ]));
    $this->get((string) $flashed['url'])->assertOk();

    $this->delete(route('twins.share-links.destroy', [$twin, $link]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Share link revoked.']);

    expect($link->fresh())->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ShareLinkRevoked)->sole()->subject)->toBe('Harbour board');
    $this->get((string) $flashed['url'])->assertNotFound();
});

it('keeps sharing to admins and owners', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    $link = TwinShareLink::factory()->for($twin)->create();

    $this->actingAs($user)->get(route('twins.show', $twin))->assertInertia(fn ($page) => $page->where('can.share', false)->where('shareLinks', []));
    $this->post(route('twins.share-links.store', $twin), ['name' => 'Board', 'expires_in_days' => 7])->assertForbidden();
    $this->delete(route('twins.share-links.destroy', [$twin, $link]))->assertForbidden();
});

it('revokes only a link of that twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $other = TwinShareLink::factory()->create();

    $this->actingAs($user)->delete(route('twins.share-links.destroy', [$twin, $other]))->assertNotFound();
});

it('validates a new link', function (array $data, string $field): void {
    [$user, $organisation] = member();

    $this->actingAs($user)->post(route('twins.share-links.store', twinIn($organisation)), $data)->assertSessionHasErrors($field);
})->with([
    [['expires_in_days' => 7], 'name'],
    [['name' => str_repeat('a', 101), 'expires_in_days' => 7], 'name'],
    [['name' => 'Board', 'expires_in_days' => 365], 'expires_in_days'],
    [['name' => 'Board'], 'expires_in_days'],
]);

it('shows the twin to anyone with the link, with its files loaded through it, from its opening view', function (): void {
    $this->freezeSecond();
    [$twin, $link, $asset] = sharedTwin();
    $view = SavedView::factory()->for($twin)->create(['state' => ['camera' => [1, 2, 3]]]);
    $twin->forceFill(['opening_view_id' => $view->id])->save();

    $response = $this->get('/share/'.SHARE_TOKEN)
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($page) => $page->component('share/show')
            ->where('twin.name', $twin->name)
            ->where('organisation', $twin->organisation->name)
            ->where('token', SHARE_TOKEN)
            ->where('live', true)
            ->where('expiresAt', $link->expires_at->toIso8601String())
            ->where('startView', ['camera' => [1, 2, 3]])
            ->where('definition.models', fn ($models): bool => collect($models)->contains(fn (array $model): bool => ($model['assetId'] ?? null) === $asset->id
                && $model['url'] === '/share/'.SHARE_TOKEN.'/assets/'.$asset->id.'/files/model.glb')));

    expect($link->fresh()->last_viewed_at?->equalTo(now()))->toBeTrue()
        ->and($response->headers->get('Content-Type'))->toContain('text/html');

    $this->get('/share/'.SHARE_TOKEN.'/assets/'.$asset->id.'/files/model.glb')
        ->assertOk()
        ->assertHeader('Content-Type', 'model/gltf-binary')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('loads terrain through the link too', function (): void {
    [$twin] = sharedTwin();
    $terrain = ProjectAsset::factory()->for($twin->project)->create(['kind' => 'terrain', 'entry' => 'layer.json']);
    Storage::disk('local')->put($terrain->directory.'/layer.json', '{}');
    resolve(AddAssetToTwin::class)->handle($twin, null, $terrain);

    $this->get('/share/'.SHARE_TOKEN)->assertInertia(fn ($page) => $page
        ->where('startView', null)
        ->where('definition.terrain.url', '/share/'.SHARE_TOKEN.'/assets/'.$terrain->id.'/files/layer.json'));
    $this->get('/share/'.SHARE_TOKEN.'/assets/'.$terrain->id.'/files/layer.json')->assertOk();
});

it('loads only the files the shared twin shows', function (): void {
    [$twin, , $asset] = sharedTwin();
    $elsewhere = ProjectAsset::factory()->for($twin->project)->create();
    Storage::disk('local')->put($elsewhere->directory.'/model.glb', 'glTF');
    $foreign = ProjectAsset::factory()->create();

    $this->get('/share/'.SHARE_TOKEN.'/assets/'.$elsewhere->id.'/files/model.glb')->assertNotFound();
    $this->get('/share/'.SHARE_TOKEN.'/assets/'.$asset->id.'/files/../secret.json')->assertNotFound();
    $this->get('/share/'.SHARE_TOKEN.'/assets/'.$foreign->id.'/files/model.glb')->assertNotFound();
});

it('refuses an asset id the twin names but that belongs to another project', function (): void {
    [$twin] = sharedTwin();
    $foreign = ProjectAsset::factory()->create();
    $definition = $twin->latestVersion()->firstOrFail()->definition;
    $definition['models'][] = ['id' => 'x', 'name' => 'X', 'url' => '/assets/'.$foreign->id.'/files/model.glb', 'assetId' => $foreign->id];
    resolve(SaveTwinVersion::class)->handle($twin, null, $definition);

    $this->get('/share/'.SHARE_TOKEN.'/assets/'.$foreign->id.'/files/model.glb')->assertNotFound();
});

it('opens nothing with an unknown, expired or revoked link', function (string $path): void {
    sharedTwin(['expires_at' => now()->subMinute()]);

    $this->get('/share/'.SHARE_TOKEN.$path)->assertNotFound();
    $this->get('/share/nonsense'.$path)->assertNotFound();
})->with(['', '/ticket', '/readings?asset=a&metric=tempC&from=1&to=2']);

it('gives a short ticket for following the gateway live, while it is online and the link allows', function (): void {
    $this->freezeSecond();
    [$twin, $link] = sharedTwin();

    $this->getJson('/share/'.SHARE_TOKEN.'/ticket')->assertExactJson(['url' => null]);

    resolve(IssueGatewayToken::class)->handle($twin);
    $twin->forceFill(['gateway_url' => 'wss://gw.example/ws', 'gateway_seen_at' => now()])->save();
    $url = $this->getJson('/share/'.SHARE_TOKEN.'/ticket')->assertOk()->json('url');

    expect($url)->toStartWith('wss://gw.example/ws?ticket=');
    [$payload] = explode('.', mb_substr((string) $url, mb_strlen('wss://gw.example/ws?ticket=')));
    expect(json_decode(base64_decode(strtr($payload, '-_', '+/')), true))->toBe([
        'twin' => $twin->id,
        'user' => 'share:'.$link->id,
        'operate' => false,
        'edit' => false,
        'exp' => now()->getTimestamp() + IssueGatewayTicket::SHARE_LIFETIME_SECONDS,
        'close' => true,
    ]);

    $twin->forceFill(['gateway_url' => 'wss://gw.example/ws?region=nz'])->save();
    expect($this->getJson('/share/'.SHARE_TOKEN.'/ticket')->json('url'))->toStartWith('wss://gw.example/ws?region=nz&ticket=');

    $twin->forceFill(['gateway_seen_at' => now()->subHour()])->save();
    $this->getJson('/share/'.SHARE_TOKEN.'/ticket')->assertExactJson(['url' => null]);

    $twin->forceFill(['gateway_seen_at' => now()])->save();
    $link->forceFill(['live' => false])->save();
    $this->getJson('/share/'.SHARE_TOKEN.'/ticket')->assertExactJson(['url' => null]);
});

it('gives the recorded history only when the link shows real readings', function (): void {
    [, $link] = sharedTwin();
    $query = '?asset=store&metric=tempC&from=1000&to=2000';

    $this->getJson('/share/'.SHARE_TOKEN.'/readings'.$query)->assertOk()->assertJsonStructure(['bucketMs', 'points']);
    $this->getJson('/share/'.SHARE_TOKEN.'/readings?asset=store')->assertUnprocessable();

    $link->forceFill(['live' => false])->save();
    $this->getJson('/share/'.SHARE_TOKEN.'/readings'.$query)->assertNotFound();
});

it('limits how often an address opens links', function (): void {
    sharedTwin();
    for ($i = 0; $i < 120; $i++) {
        RateLimiter::hit(md5('share'.'share:127.0.0.1'));
    }

    $this->get('/share/'.SHARE_TOKEN)->assertTooManyRequests();
});
