import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import { destroy, index, store } from '@/routes/organisation/api-tokens';
import type { BreadcrumbItem } from '@/types';

type Token = {
    id: string;
    name: string;
    masked: string;
    access: string;
    createdBy: string | null;
    createdAt: string;
    expiresAt: string | null;
    expired: boolean;
    lastUsedAt: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'API', href: index() }];

const selectClass = 'h-9 rounded-md border bg-transparent px-2 text-sm';

const EXPIRY = [
    { value: '30', label: 'In 30 days' },
    { value: '90', label: 'In 90 days' },
    { value: '365', label: 'In a year' },
    { value: '', label: 'Never' },
];

const day = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/**
 * The organisation's API tokens. A new token is flashed once by the server
 * and shown here with a request to try it; only its last four characters
 * are kept for the list.
 */
export default function ApiTokens({
    currentOrganisation: organisation,
    tokens,
    accessLevels,
    baseUrl,
    canManage,
}: {
    currentOrganisation: { id: string; name: string };
    tokens: Token[];
    accessLevels: { value: string; label: string }[];
    baseUrl: string;
    canManage: boolean;
}) {
    const form = useForm<{
        name: string;
        access: string;
        expires_in_days: string;
    }>({
        name: '',
        access: accessLevels[0]?.value ?? 'read',
        expires_in_days: '90',
    });
    const [issued, setIssued] = useState<{
        tokenId: string;
        token: string;
    } | null>(null);
    const [copied, copy] = useClipboard();

    useEffect(
        () =>
            router.on('flash', (event) => {
                const token = event.detail.flash.apiToken;
                if (token) setIssued(token);
            }),
        [],
    );

    const shown = issued
        ? tokens.find((t) => t.id === issued.tokenId)
        : undefined;
    const example = issued
        ? `curl -H "Authorization: Bearer ${issued.token}" ${baseUrl}/twins`
        : '';

    return (
        <>
            <Head title="API" />
            <div className="flex max-w-3xl flex-col gap-8 p-4 md:p-6">
                <Heading
                    title="API"
                    description={`Tokens let other systems, such as a maintenance system or a BI tool, read ${organisation.name}'s projects, twins, readings, alerts and issues. Tokens that may write also raise, comment on and close issues, and acknowledge alerts, signed with the token's name.`}
                />

                {shown && issued && (
                    <div
                        className="grid gap-3 rounded-xl border border-amber-500/50 p-4"
                        role="status"
                        aria-label="New token"
                    >
                        <p className="text-sm">
                            <span className="font-medium">{shown.name}</span>:
                            copy this token now. It won't be shown again.
                        </p>
                        <Input
                            aria-label="Token"
                            readOnly
                            value={issued.token}
                            onFocus={(e) => e.target.select()}
                            className="font-mono"
                        />
                        <p className="text-sm text-muted-foreground">Try it:</p>
                        <code className="rounded-md bg-muted p-2 text-xs break-all">
                            {example}
                        </code>
                        <div>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => void copy(issued.token)}
                            >
                                {copied === issued.token
                                    ? 'Copied'
                                    : 'Copy token'}
                            </Button>
                        </div>
                    </div>
                )}

                {tokens.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No tokens yet.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="py-2 font-medium">Name</th>
                                <th className="py-2 font-medium">Access</th>
                                <th className="py-2 font-medium">Last used</th>
                                {canManage && (
                                    <th className="sr-only">Actions</th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {tokens.map((t) => (
                                <tr key={t.id} className="border-t">
                                    <td className="py-3">
                                        <div className="font-medium">
                                            {t.name}{' '}
                                            <code className="text-xs text-muted-foreground">
                                                {t.masked}
                                            </code>
                                        </div>
                                        <div className="text-muted-foreground">
                                            {t.createdBy
                                                ? `Made by ${t.createdBy}`
                                                : 'Made'}{' '}
                                            on {day(t.createdAt)}
                                            {' · '}
                                            {t.expired ? (
                                                <span className="text-destructive">
                                                    Expired {day(t.expiresAt!)}
                                                </span>
                                            ) : t.expiresAt ? (
                                                `Expires ${day(t.expiresAt)}`
                                            ) : (
                                                'Never expires'
                                            )}
                                        </div>
                                    </td>
                                    <td className="py-3">{t.access}</td>
                                    <td className="py-3">
                                        {t.lastUsedAt
                                            ? day(t.lastUsedAt)
                                            : 'Never'}
                                    </td>
                                    {canManage && (
                                        <td className="py-3 text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    confirm(
                                                        `Revoke ${t.name}? Anything using it stops working at once.`,
                                                    ) &&
                                                    router.delete(
                                                        destroy.url(t.id),
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                if (
                                                                    issued?.tokenId ===
                                                                    t.id
                                                                )
                                                                    setIssued(
                                                                        null,
                                                                    );
                                                            },
                                                        },
                                                    )
                                                }
                                            >
                                                Revoke
                                            </Button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {canManage ? (
                    <form
                        className="grid gap-4 rounded-xl border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(store.url(), {
                                preserveScroll: true,
                                onSuccess: () => form.reset('name'),
                            });
                        }}
                    >
                        <Heading
                            variant="small"
                            title="Make a token"
                            description="It belongs to the organisation, so it keeps working if you leave. Name it after what will use it."
                        />
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="token-name">Name</Label>
                                <Input
                                    id="token-name"
                                    required
                                    maxLength={100}
                                    placeholder="Maintenance system"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="token-access">Access</Label>
                                <select
                                    id="token-access"
                                    className={selectClass}
                                    value={form.data.access}
                                    onChange={(e) =>
                                        form.setData('access', e.target.value)
                                    }
                                >
                                    {accessLevels.map((a) => (
                                        <option key={a.value} value={a.value}>
                                            {a.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="token-expiry">Expires</Label>
                                <select
                                    id="token-expiry"
                                    className={selectClass}
                                    value={form.data.expires_in_days}
                                    onChange={(e) =>
                                        form.setData(
                                            'expires_in_days',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {EXPIRY.map((x) => (
                                        <option key={x.value} value={x.value}>
                                            {x.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Make token
                            </Button>
                        </div>
                        <InputError message={form.errors.name} />
                    </form>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Admins and owners make and revoke tokens.
                    </p>
                )}
            </div>
        </>
    );
}

ApiTokens.layout = [AppLayout, { breadcrumbs }];
