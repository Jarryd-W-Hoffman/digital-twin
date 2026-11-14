<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\SsoConnection;
use App\Support\OpenIdConnect;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sets up or changes an organisation's identity provider, once its
 * discovery document has been found, and the email domains that sign in
 * through it.
 */
final readonly class SaveSsoConnection
{
    public function __construct(private OpenIdConnect $oidc, private RecordAuditEvent $audit) {}

    /**
     * @param  array{issuer: string, client_id: string, client_secret: string|null, domains: list<string>, required: bool, auto_join: bool, default_role: OrganisationRole}  $settings
     */
    public function handle(Organisation $organisation, array $settings): SsoConnection
    {
        try {
            $this->oidc->discover($settings['issuer'], fresh: true);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages(['issuer' => $invalidArgumentException->getMessage()]);
        }

        return DB::transaction(function () use ($organisation, $settings): SsoConnection {
            $connection = $organisation->ssoConnection()->firstOrNew();
            $connection->fill([
                'issuer' => $settings['issuer'],
                'client_id' => $settings['client_id'],
                'required' => $settings['required'],
                'auto_join' => $settings['auto_join'],
                'default_role' => $settings['default_role'],
                ...($settings['client_secret'] === null ? [] : ['client_secret' => $settings['client_secret']]),
            ])->save();

            $connection->domains()->whereNotIn('domain', $settings['domains'])->delete();
            foreach ($settings['domains'] as $domain) {
                $connection->domains()->firstOrCreate(['domain' => $domain]);
            }

            $this->audit->handle(AuditAction::SsoSaved, $organisation, $settings['issuer'], [
                'domains' => implode(', ', $settings['domains']),
                'required' => $settings['required'] ? 'yes' : 'no',
                'autoJoin' => $settings['auto_join'] ? $settings['default_role']->value : 'off',
            ]);

            return $connection->load('domains');
        });
    }
}
