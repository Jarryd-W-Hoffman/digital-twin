import { Head, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { destroy, index, store } from '@/routes/user-sso';
import type { BreadcrumbItem } from '@/types';

type Identity = {
    id: number;
    organisation: string;
    email: string;
    lastSignedInAt: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Single sign-on', href: index() },
];

const day = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/**
 * The organisations' identity providers someone signs in through. Linking
 * one goes through the provider while signed in, so an account is only ever
 * linked by its owner.
 */
export default function SsoIdentities({
    identities,
    available,
}: {
    identities: Identity[];
    available: { organisation: string } | null;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Single sign-on" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Single sign-on"
                    description="Sign in through your organisation's identity provider instead of a password. It handles your second factor."
                />

                {identities.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Not linked to any identity provider.
                    </p>
                ) : (
                    <ul className="grid gap-3" aria-label="Linked identities">
                        {identities.map((identity) => (
                            <li
                                key={identity.id}
                                className="flex items-center justify-between gap-4 rounded-lg border p-4"
                            >
                                <div className="text-sm">
                                    <div className="font-medium">
                                        {identity.organisation}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {identity.email}
                                        {identity.lastSignedInAt &&
                                            ` · last signed in ${day(identity.lastSignedInAt)}`}
                                    </div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        confirm(
                                            `Stop signing in through ${identity.organisation}'s identity provider?`,
                                        ) &&
                                        router.delete(
                                            destroy.url(identity.id),
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Unlink
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {available && (
                    <div className="grid gap-3 rounded-lg border p-4">
                        <p className="text-sm">
                            {available.organisation} signs in through single
                            sign-on. Link your account to sign in that way;
                            you'll be sent to its identity provider and back.
                        </p>
                        <div>
                            <Button onClick={() => router.post(store.url())}>
                                Link {available.organisation}
                            </Button>
                        </div>
                    </div>
                )}

                <InputError message={errors.sso} />
            </div>
        </>
    );
}

SsoIdentities.layout = [[AppLayout, { breadcrumbs }], SettingsLayout];
