<?php

declare(strict_types=1);

use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;
use App\Support\Actor;

it('keeps a person by id and a token by name', function (): void {
    $ana = User::factory()->make(['id' => 'u1', 'name' => 'Ana']);
    $token = ApiToken::factory()->make(['name' => 'CMMS']);

    expect(Actor::columns($ana))->toBe(['u1', null])
        ->and(Actor::columns($token))->toBe([null, 'CMMS'])
        ->and(Actor::name($ana))->toBe('Ana')
        ->and(Actor::name($token))->toBe('CMMS (API)')
        ->and($token->signature())->toBe('CMMS (API)')
        ->and(Actor::apiName(null))->toBeNull();
});

it('names who raised, closed, wrote or acknowledged something', function (): void {
    $ana = User::factory()->create(['name' => 'Ana']);
    $byPerson = Issue::factory()->create(['author_id' => $ana->id, 'closed_at' => now(), 'closed_by' => $ana->id]);
    $byToken = Issue::factory()->create(['author_id' => null, 'author_api' => 'CMMS', 'closed_at' => now(), 'closed_by_api' => 'CMMS']);
    $byNobody = Issue::factory()->create(['author_id' => null]);

    expect($byPerson->authorName())->toBe('Ana')
        ->and($byPerson->closerName())->toBe('Ana')
        ->and($byToken->authorName())->toBe('CMMS (API)')
        ->and($byToken->closerName())->toBe('CMMS (API)')
        ->and($byNobody->authorName())->toBeNull()
        ->and($byNobody->closerName())->toBeNull()
        ->and(IssueComment::factory()->create(['author_id' => $ana->id])->authorName())->toBe('Ana')
        ->and(IssueComment::factory()->create(['author_id' => null, 'author_api' => 'CMMS'])->authorName())->toBe('CMMS (API)')
        ->and(Alert::factory()->create(['acknowledged_at' => now(), 'acknowledged_by' => $ana->id])->acknowledgerName())->toBe('Ana')
        ->and(Alert::factory()->create(['acknowledged_at' => now(), 'acknowledged_by_api' => 'CMMS'])->acknowledgerName())->toBe('CMMS (API)')
        ->and(Alert::factory()->create()->acknowledgerName())->toBeNull();
});
