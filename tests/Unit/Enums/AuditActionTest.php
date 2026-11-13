<?php

declare(strict_types=1);

use App\Enums\AuditAction;

it('labels every action and files it under a known area', function (AuditAction $action): void {
    expect($action->label())->toBeString()->not->toBeEmpty()
        ->and(AuditAction::areas())->toHaveKey($action->area())
        ->and(AuditAction::in($action->area()))->toContain($action);
})->with(AuditAction::cases());

it('lists the actions in an area', function (): void {
    expect(AuditAction::in('access'))->toBe([
        AuditAction::MemberAdded,
        AuditAction::MemberRoleChanged,
        AuditAction::MemberRemoved,
        AuditAction::ApiTokenCreated,
        AuditAction::ApiTokenRevoked,
    ])->and(AuditAction::in('nowhere'))->toBe([]);
});
