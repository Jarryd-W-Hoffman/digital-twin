<?php

declare(strict_types=1);

use App\Actions\DeleteSsoConnection;
use App\Actions\SaveSsoConnection;
use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function ssoSettings(array $settings = []): array
{
    return [
        'issuer' => IDP_ISSUER,
        'client_id' => 'meridian',
        'client_secret' => 'idp-secret',
        'domains' => ['harbour.example'],
        'required' => false,
        'auto_join' => false,
        'default_role' => OrganisationRole::Viewer,
        ...$settings,
    ];
}

it('sets up an identity provider and its domains, and records it', function (): void {
    fakeIdentityProvider();
    $organisation = Organisation::factory()->create();

    $connection = resolve(SaveSsoConnection::class)->handle($organisation, ssoSettings(['domains' => ['harbour.example', 'harbour.example.nz'], 'required' => true, 'auto_join' => true, 'default_role' => OrganisationRole::Operator]));

    expect($connection->fresh())
        ->issuer->toBe(IDP_ISSUER)
        ->client_secret->toBe('idp-secret')
        ->required->toBeTrue()
        ->auto_join->toBeTrue()
        ->default_role->toBe(OrganisationRole::Operator)
        ->and($connection->domains->pluck('domain')->all())->toBe(['harbour.example', 'harbour.example.nz'])
        ->and(AuditEvent::query()->sole())
        ->action->toBe(AuditAction::SsoSaved)
        ->details->toBe(['domains' => 'harbour.example, harbour.example.nz', 'required' => 'yes', 'autoJoin' => 'operator']);
});

it('changes it, keeping the secret unless a new one is given', function (): void {
    fakeIdentityProvider();
    $connection = SsoConnection::factory()->domains(['harbour.example', 'old.example'])->create();

    resolve(SaveSsoConnection::class)->handle($connection->organisation, ssoSettings(['client_id' => 'meridian-2', 'client_secret' => null, 'domains' => ['harbour.example', 'new.example']]));

    expect($connection->fresh())
        ->client_id->toBe('meridian-2')
        ->client_secret->toBe('idp-secret')
        ->domains->pluck('domain')->sort()->values()->all()->toBe(['harbour.example', 'new.example'])
        ->and(SsoConnection::query()->count())->toBe(1)
        ->and(AuditEvent::query()->sole()->details)->toBe(['domains' => 'harbour.example, new.example', 'required' => 'no', 'autoJoin' => 'off']);
});

it('refuses an issuer without a discovery document', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    expect(fn () => resolve(SaveSsoConnection::class)->handle(Organisation::factory()->create(), ssoSettings()))
        ->toThrow(ValidationException::class, 'no OpenID Connect discovery document');
    expect(SsoConnection::query()->count())->toBe(0);
});

it('turns single sign-on off, unlinking its people', function (): void {
    $connection = SsoConnection::factory()->domains()->create();
    $identity = SsoIdentity::factory()->for($connection, 'connection')->create();

    resolve(DeleteSsoConnection::class)->handle($connection);

    $this->assertModelMissing($connection);
    $this->assertModelMissing($identity);
    expect(AuditEvent::query()->sole())->action->toBe(AuditAction::SsoRemoved)->subject->toBe(IDP_ISSUER);
});
