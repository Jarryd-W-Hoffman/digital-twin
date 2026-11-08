<?php

declare(strict_types=1);

use App\Actions\IssueGatewayTicket;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * @return array{token: string, twin: string, user: string, operate: bool, edit: bool, issuedAt: int, ticket: string}
 */
function ticketFixture(): array
{
    /** @var array{token: string, twin: string, user: string, operate: bool, edit: bool, issuedAt: int, ticket: string} */
    return json_decode((string) file_get_contents(base_path('tests/fixtures/gateway-ticket.json')), true);
}

it('signs exactly the ticket the gateway checks', function (): void {
    $fixture = ticketFixture();
    Date::setTestNow(Date::createFromTimestamp($fixture['issuedAt']));
    $twin = Twin::factory()->create(['id' => $fixture['twin'], 'gateway_token_hash' => hash('sha256', $fixture['token'])]);
    $user = User::factory()->create(['id' => $fixture['user']]);

    expect(resolve(IssueGatewayTicket::class)->handle($twin, $user, $fixture['operate'], $fixture['edit']))->toBe($fixture['ticket']);
});

it('carries the twin, the user, whether they may operate and edit, and an expiry', function (): void {
    $this->freezeSecond();
    $twin = Twin::factory()->create(['gateway_token_hash' => str_repeat('b', 64)]);
    $user = User::factory()->create();

    [$payload, $signature] = explode('.', resolve(IssueGatewayTicket::class)->handle($twin, $user, true, false));

    expect(json_decode(base64_decode(strtr($payload, '-_', '+/')), true))->toBe([
        'twin' => $twin->id,
        'user' => $user->id,
        'operate' => true,
        'edit' => false,
        'exp' => now()->getTimestamp() + IssueGatewayTicket::LIFETIME_SECONDS,
    ])->and($signature)->toMatch('/^[A-Za-z0-9_-]{43}$/');
});

it('needs a gateway token', function (): void {
    resolve(IssueGatewayTicket::class)->handle(Twin::factory()->create(), User::factory()->create(), false, false);
})->throws(LogicException::class, 'The twin has no gateway token.');
