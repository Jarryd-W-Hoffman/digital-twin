<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;

it('grants each role its abilities', function (OrganisationRole $role, string $label, bool $edit, bool $operate, bool $manage): void {
    expect($role->label())->toBe($label)
        ->and($role->canEditTwins())->toBe($edit)
        ->and($role->canOperate())->toBe($operate)
        ->and($role->canManageMembers())->toBe($manage);
})->with([
    [OrganisationRole::Owner, 'Owner', true, true, true],
    [OrganisationRole::Admin, 'Admin', true, true, true],
    [OrganisationRole::Operator, 'Operator', false, true, false],
    [OrganisationRole::Viewer, 'Viewer', false, false, false],
]);
