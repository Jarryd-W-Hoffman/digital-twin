import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import { destroy, show, update } from '@/routes/organisation/sso';
import scimToken from '@/routes/organisation/sso/scim-token';
import type { BreadcrumbItem } from '@/types';

type Connection = {
    issuer: string;
    clientId: string;
    domains: string;
    required: boolean;
    autoJoin: boolean;
    defaultRole: string;
    linked: number;
    scim: { hint: string | null; usedAt: string | null; provisioned: number };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Single sign-on', href: show() },
];

const selectClass = 'h-9 rounded-md border bg-transparent px-2 text-sm';

/**
 * The organisation's OpenID Connect identity provider, for its owners: the
 * address to register with it, its issuer and client, the email domains
 * that sign in through it, and whether newcomers join.
 */
export default function Sso({
    currentOrganisation: organisation,
    connection,
    redirectUri,
    scimUrl,
    roles,
}: {
    currentOrganisation: { id: string; name: string };
    connection: Connection | null;
    redirectUri: string;
    scimUrl: string;
    roles: { value: string; label: string }[];
}) {
    const form = useForm({
        issuer: connection?.issuer ?? '',
        client_id: connection?.clientId ?? '',
        client_secret: '',
        domains: connection?.domains ?? '',
        required: connection?.required ?? false,
        auto_join: connection?.autoJoin ?? false,
        default_role: connection?.defaultRole ?? 'viewer',
    });
    const [copied, copy] = useClipboard();
    const [issued, setIssued] = useState<string | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const token = event.detail.flash.scimToken;
                if (token) setIssued(token);
            }),
        [],
    );

    return (
        <>
            <Head title="Single sign-on" />
            <div className="flex max-w-3xl flex-col gap-8 p-4 md:p-6">
                <Heading
                    title="Single sign-on"
                    description={`People with an email in ${organisation.name}'s domains sign in through its identity provider (Microsoft Entra, Okta, Google, Keycloak or any OpenID Connect provider), which handles their passwords and second factor.`}
                />

                <div className="grid gap-2 rounded-xl border p-4">
                    <Heading
                        variant="small"
                        title="1. Register Meridian with the identity provider"
                        description="As a web application, with this redirect URI. Give it the openid, email and profile scopes."
                    />
                    <div className="flex gap-2">
                        <Input
                            aria-label="Redirect URI"
                            readOnly
                            value={redirectUri}
                            className="font-mono"
                            onFocus={(e) => e.target.select()}
                        />
                        <Button
                            variant="secondary"
                            onClick={() => void copy(redirectUri)}
                        >
                            {copied === redirectUri ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                </div>

                <form
                    className="grid gap-4 rounded-xl border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(update.url(), {
                            preserveScroll: true,
                            onSuccess: () => form.reset('client_secret'),
                        });
                    }}
                >
                    <Heading
                        variant="small"
                        title="2. Connect it"
                        description="Meridian checks the issuer's discovery document when you save."
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="sso-issuer">Issuer</Label>
                        <Input
                            id="sso-issuer"
                            required
                            placeholder="https://login.microsoftonline.com/{tenant}/v2.0"
                            value={form.data.issuer}
                            onChange={(e) =>
                                form.setData('issuer', e.target.value)
                            }
                        />
                        <InputError message={form.errors.issuer} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="sso-client">Client ID</Label>
                            <Input
                                id="sso-client"
                                required
                                value={form.data.client_id}
                                onChange={(e) =>
                                    form.setData('client_id', e.target.value)
                                }
                            />
                            <InputError message={form.errors.client_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sso-secret">Client secret</Label>
                            <Input
                                id="sso-secret"
                                type="password"
                                autoComplete="off"
                                required={connection === null}
                                placeholder={
                                    connection
                                        ? 'Kept; enter a new one to replace it'
                                        : ''
                                }
                                value={form.data.client_secret}
                                onChange={(e) =>
                                    form.setData(
                                        'client_secret',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.client_secret} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sso-domains">Email domains</Label>
                        <Input
                            id="sso-domains"
                            required
                            placeholder="northshore.example, northshore-logistics.example"
                            value={form.data.domains}
                            onChange={(e) =>
                                form.setData('domains', e.target.value)
                            }
                        />
                        <InputError message={form.errors.domains} />
                    </div>
                    <div className="grid gap-1">
                        <div className="flex items-center gap-3">
                            <Checkbox
                                id="sso-required"
                                checked={form.data.required}
                                onCheckedChange={(checked) =>
                                    form.setData('required', checked === true)
                                }
                            />
                            <Label htmlFor="sso-required">
                                Require single sign-on for everyone but owners
                            </Label>
                        </div>
                        <p className="pl-7 text-sm text-muted-foreground">
                            Members who signed in with a password or passkey go
                            through the identity provider before they can work
                            here. Owners can always come in, in case it breaks.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Checkbox
                            id="sso-auto-join"
                            checked={form.data.auto_join}
                            onCheckedChange={(checked) =>
                                form.setData('auto_join', checked === true)
                            }
                        />
                        <Label htmlFor="sso-auto-join">
                            Add people signing in for the first time as
                        </Label>
                        <select
                            aria-label="Role for new people"
                            className={selectClass}
                            value={form.data.default_role}
                            disabled={!form.data.auto_join}
                            onChange={(e) =>
                                form.setData('default_role', e.target.value)
                            }
                        >
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        People who already have a Meridian account link it under
                        Settings, Single sign-on, so the identity provider can
                        never take over an account on its own.
                    </p>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </div>
                </form>

                {connection && (
                    <section
                        className="grid gap-4 rounded-xl border p-4"
                        aria-label="Automatic provisioning"
                    >
                        <Heading
                            variant="small"
                            title="3. Provision people automatically (optional)"
                            description="Give the identity provider this SCIM address and token, and it adds people to the organisation, and removes them when they leave, without anyone doing it here. People in the domains above join with the role for new people; owners are never removed."
                        />
                        <div className="grid gap-2">
                            <Label htmlFor="sso-scim-url">SCIM base URL</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="sso-scim-url"
                                    readOnly
                                    value={scimUrl}
                                    className="font-mono"
                                />
                                <Button
                                    variant="secondary"
                                    onClick={() => void copy(scimUrl)}
                                >
                                    {copied === scimUrl ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                        </div>
                        {issued && (
                            <div
                                className="grid gap-2 rounded-md border border-amber-500/50 p-3"
                                role="status"
                                aria-label="New provisioning token"
                            >
                                <p className="text-sm">
                                    Copy this token into the identity provider
                                    now. It won't be shown again.
                                </p>
                                <div className="flex gap-2">
                                    <Input
                                        aria-label="Provisioning token"
                                        readOnly
                                        value={issued}
                                        onFocus={(e) => e.target.select()}
                                        className="font-mono"
                                    />
                                    <Button
                                        variant="secondary"
                                        onClick={() => void copy(issued)}
                                    >
                                        {copied === issued ? 'Copied' : 'Copy'}
                                    </Button>
                                </div>
                            </div>
                        )}
                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span className="text-muted-foreground">
                                {connection.scim.hint
                                    ? `Token mrs_…${connection.scim.hint} · ${connection.scim.usedAt ? `last used ${new Date(connection.scim.usedAt).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}` : 'not used yet'} · ${connection.scim.provisioned === 1 ? '1 person' : `${connection.scim.provisioned} people`} provisioned`
                                    : 'No provisioning token yet.'}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() =>
                                        (!connection.scim.hint ||
                                            confirm(
                                                'Make a new provisioning token? The current one stops working at once.',
                                            )) &&
                                        router.post(
                                            scimToken.store.url(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {connection.scim.hint
                                        ? 'Make a new token'
                                        : 'Make provisioning token'}
                                </Button>
                                {connection.scim.hint && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            confirm(
                                                'Revoke the provisioning token? The identity provider stops adding and removing people; everyone it added stays.',
                                            ) &&
                                            router.delete(
                                                scimToken.destroy.url(),
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        setIssued(null),
                                                },
                                            )
                                        }
                                    >
                                        Revoke
                                    </Button>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                {connection && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm">
                        <span>
                            {connection.linked === 1
                                ? '1 person signs in'
                                : `${connection.linked} people sign in`}{' '}
                            through it.
                        </span>
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() =>
                                confirm(
                                    'Turn off single sign-on? People keep their accounts and sign in with a password or passkey.',
                                ) &&
                                router.delete(destroy.url(), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Turn off single sign-on
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

Sso.layout = [AppLayout, { breadcrumbs }];
