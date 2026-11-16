import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/organisation/features';
import type { BreadcrumbItem } from '@/types';

type FeatureRow = {
    value: string;
    label: string;
    description: string;
    enabled: boolean;
    /** Why this server can't offer it, or null when it can. */
    unavailable: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Features', href: index() }];

/**
 * The organisation's optional features. Admins and owners switch each on or
 * off for everyone in it; one the server can't offer says why, and can only
 * be switched off.
 */
export default function Features({
    currentOrganisation: organisation,
    features,
}: {
    currentOrganisation: { id: string; name: string };
    features: FeatureRow[];
}) {
    const [errors, setErrors] = useState<Record<string, string>>({});
    // The switch moves at once, and springs back if the change is refused.
    const [pending, setPending] = useState<Record<string, boolean>>({});

    const change = (feature: FeatureRow, enabled: boolean) => {
        setPending((all) => ({ ...all, [feature.value]: enabled }));
        router.put(
            update.url(feature.value),
            { enabled },
            {
                preserveScroll: true,
                onError: (e) =>
                    setErrors((all) => ({
                        ...all,
                        [feature.value]: e.enabled ?? '',
                    })),
                onSuccess: () =>
                    setErrors((all) => ({ ...all, [feature.value]: '' })),
                onFinish: () =>
                    setPending((all) => {
                        const rest = { ...all };
                        delete rest[feature.value];

                        return rest;
                    }),
            },
        );
    };

    return (
        <>
            <Head title="Features" />
            <div className="flex max-w-3xl flex-col gap-8 p-4 md:p-6">
                <Heading
                    title="Features"
                    description={`Optional parts of Meridian, switched on or off for everyone in ${organisation.name}. Each change is kept in the audit log.`}
                />
                <ul className="grid gap-3" aria-label="Features">
                    {features.map((feature) => {
                        const id = `feature-${feature.value}`;
                        const locked =
                            feature.unavailable !== null && !feature.enabled;

                        return (
                            <li
                                key={feature.value}
                                aria-label={feature.label}
                                className="grid gap-2 rounded-xl border p-4"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <div className="grid gap-1">
                                        <label
                                            htmlFor={id}
                                            className="font-medium"
                                        >
                                            {feature.label}
                                        </label>
                                        <p className="text-sm text-muted-foreground">
                                            {feature.description}
                                        </p>
                                    </div>
                                    <input
                                        id={id}
                                        type="checkbox"
                                        role="switch"
                                        className="mt-1 size-5 shrink-0 accent-primary"
                                        checked={
                                            pending[feature.value] ??
                                            feature.enabled
                                        }
                                        disabled={
                                            locked || feature.value in pending
                                        }
                                        onChange={(e) =>
                                            change(feature, e.target.checked)
                                        }
                                    />
                                </div>
                                {feature.unavailable !== null && (
                                    <p
                                        className="text-sm text-amber-600 dark:text-amber-400"
                                        role="note"
                                    >
                                        {feature.unavailable}
                                        {feature.enabled &&
                                            ' It stays off until the server offers it.'}
                                    </p>
                                )}
                                <InputError message={errors[feature.value]} />
                            </li>
                        );
                    })}
                </ul>
            </div>
        </>
    );
}

Features.layout = [AppLayout, { breadcrumbs }];
