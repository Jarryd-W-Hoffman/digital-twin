import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index } from '@/routes/organisation/audit';
import type { BreadcrumbItem } from '@/types';

type AuditEvent = {
    id: string;
    at: string;
    actor: string;
    viaApi: boolean;
    action: string;
    label: string;
    subject: string | null;
    project: { id: string; name: string | null } | null;
    twin: { id: string; name: string | null } | null;
    details: Record<string, unknown>;
    ip: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: index() }];

const selectClass = 'h-9 rounded-md border bg-transparent px-2 text-sm';

const when = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

/** "expiresAt" as "expires at". */
const words = (key: string) =>
    key.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase();

/** A time as a date and time, a day as a date, anything else as it is. */
const value = (v: unknown) => {
    const text = String(v);
    if (/^\d{4}-\d{2}-\d{2}T/.test(text)) return when(text);
    if (/^\d{4}-\d{2}-\d{2}$/.test(text))
        return new Date(`${text}T00:00:00`).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    return text;
};

/** Details as "key: value", leaving out empty ones. */
const details = (values: Record<string, unknown>) =>
    Object.entries(values)
        .filter(([, v]) => v !== null && v !== '')
        .map(([key, v]) => `${words(key)}: ${value(v)}`)
        .join(' · ');

/**
 * The organisation's audit log: who did what, newest first, with where it
 * happened and the details kept with it.
 */
export default function Audit({
    currentOrganisation: organisation,
    events,
    areas,
    area,
    next,
    previous,
    retentionDays,
}: {
    currentOrganisation: { id: string; name: string };
    events: AuditEvent[];
    areas: { value: string; label: string }[];
    area: string | null;
    next: string | null;
    previous: string | null;
    retentionDays: number;
}) {
    return (
        <>
            <Head title="Audit log" />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Audit log"
                    description={`Who did what in ${organisation.name}: members, API tokens, projects, twins, connections, alerts and issues. Kept for ${retentionDays} days.`}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <label htmlFor="audit-area" className="text-sm">
                        Show
                    </label>
                    <select
                        id="audit-area"
                        className={selectClass}
                        value={area ?? ''}
                        onChange={(e) =>
                            router.get(
                                index.url({
                                    query: e.target.value
                                        ? { area: e.target.value }
                                        : {},
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <option value="">Everything</option>
                        {areas.map((a) => (
                            <option key={a.value} value={a.value}>
                                {a.label}
                            </option>
                        ))}
                    </select>
                </div>

                {events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing recorded yet.
                    </p>
                ) : (
                    <table className="w-full text-sm" aria-label="Audit log">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="py-2 pr-4 font-medium">When</th>
                                <th className="py-2 pr-4 font-medium">Who</th>
                                <th className="py-2 font-medium">What</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((e) => (
                                <tr key={e.id} className="border-t align-top">
                                    <td className="py-3 pr-4 whitespace-nowrap text-muted-foreground">
                                        {when(e.at)}
                                    </td>
                                    <td className="py-3 pr-4">
                                        <div>{e.actor}</div>
                                        {e.ip && (
                                            <div className="text-xs text-muted-foreground">
                                                {e.ip}
                                            </div>
                                        )}
                                    </td>
                                    <td className="py-3">
                                        <div>
                                            <span className="font-medium">
                                                {e.label}
                                            </span>
                                            {e.subject && <>: {e.subject}</>}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {[e.project?.name, e.twin?.name]
                                                .filter(Boolean)
                                                .join(' › ')}
                                            {details(e.details) && (
                                                <>
                                                    {e.project || e.twin
                                                        ? ' · '
                                                        : ''}
                                                    {details(e.details)}
                                                </>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {(next || previous) && (
                    <div className="flex gap-2">
                        {previous && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={previous} preserveScroll>
                                    Newer
                                </Link>
                            </Button>
                        )}
                        {next && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={next} preserveScroll>
                                    Older
                                </Link>
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

Audit.layout = [AppLayout, { breadcrumbs }];
