import { Head, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { required } from '@/routes/sso';
import { store } from '@/routes/sso/required';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Single sign-on', href: required() },
];

/**
 * The organisation requires single sign-on and this session hasn't signed
 * in through it: one step through its identity provider, which links the
 * account too if it isn't yet.
 */
export default function SsoRequired({
    organisation,
    linked,
}: {
    organisation: string;
    linked: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Single sign-on required" />
            <div className="flex max-w-xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Single sign-on required"
                    description={`${organisation} asks everyone to sign in through its identity provider. ${linked ? 'Continue to sign in there, and you’ll come straight back.' : 'Continue to sign in there; this also links your Meridian account to it.'}`}
                />
                <div>
                    <Button onClick={() => router.post(store.url())}>
                        Continue with single sign-on
                    </Button>
                </div>
                <InputError message={errors.sso} />
                <p className="text-sm text-muted-foreground">
                    Your other organisations are still in the switcher at the
                    top of the sidebar.
                </p>
            </div>
        </>
    );
}

SsoRequired.layout = [AppLayout, { breadcrumbs }];
