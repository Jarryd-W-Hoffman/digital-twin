<?php

declare(strict_types=1);

use App\Support\ScimAttributes;
use App\Support\ScimPatch;

const SCIM_CURRENT = [
    'userName' => 'ana@harbour.example',
    'externalId' => 'entra-ana',
    'name' => ['formatted' => 'Ana Ruiz'],
    'displayName' => 'Ana Ruiz',
    'emails' => [['value' => 'ana@harbour.example', 'primary' => true]],
    'active' => true,
];

it('follows the attributes Meridian keeps, and ignores the rest', function (array $operations, string $userName, ?string $externalId, ?string $name, bool $active): void {
    $attributes = ScimAttributes::from(ScimPatch::apply(SCIM_CURRENT, ['Operations' => $operations]));

    expect($attributes)
        ->userName->toBe($userName)
        ->externalId->toBe($externalId)
        ->name->toBe($name)
        ->active->toBe($active)
        ->email->toBe('ana@harbour.example');
})->with([
    'a new name' => [[['op' => 'replace', 'path' => 'name', 'value' => ['givenName' => 'Ana', 'familyName' => 'Ng']]], 'ana@harbour.example', 'entra-ana', 'Ana Ng', true],
    'a formatted name' => [[['op' => 'replace', 'path' => 'name.formatted', 'value' => 'Ana R. Ng']], 'ana@harbour.example', 'entra-ana', 'Ana R. Ng', true],
    'a family name' => [[['op' => 'replace', 'path' => 'name.familyName', 'value' => 'Ng']], 'ana@harbour.example', 'entra-ana', 'Ng', true],
    'a display name only' => [[['op' => 'remove', 'path' => 'name'], ['op' => 'replace', 'path' => 'displayName', 'value' => 'Ana']], 'ana@harbour.example', 'entra-ana', 'Ana', true],
    'no name at all' => [[['op' => 'remove', 'path' => 'name'], ['op' => 'remove', 'path' => 'displayName']], 'ana@harbour.example', 'entra-ana', null, true],
    'a new userName and externalId' => [[['op' => 'Add', 'path' => 'userName', 'value' => 'Ana.Ng@harbour.example'], ['op' => 'replace', 'path' => 'externalId', 'value' => 'entra-2']], 'ana.ng@harbour.example', 'entra-2', 'Ana Ruiz', true],
    'no externalId' => [[['op' => 'remove', 'path' => 'externalId']], 'ana@harbour.example', null, 'Ana Ruiz', true],
    'removed active' => [[['op' => 'remove', 'path' => 'active']], 'ana@harbour.example', 'entra-ana', 'Ana Ruiz', false],
    'emails and the rest' => [[['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'x@elsewhere.example'], ['op' => 'replace', 'path' => 'title', 'value' => 'Engineer']], 'ana@harbour.example', 'entra-ana', 'Ana Ruiz', true],
]);

it('takes the primary email, or the first', function (): void {
    expect(ScimAttributes::from(['userName' => 'ana', 'emails' => [['value' => 'a@x.example'], ['value' => 'b@x.example', 'primary' => 'True']]])->email)->toBe('b@x.example')
        ->and(ScimAttributes::from(['userName' => 'ana', 'emails' => ['junk', ['value' => 'a@x.example']]])->email)->toBe('a@x.example')
        ->and(ScimAttributes::from(['userName' => 'ana', 'emails' => 'junk'])->email)->toBeNull();
});
