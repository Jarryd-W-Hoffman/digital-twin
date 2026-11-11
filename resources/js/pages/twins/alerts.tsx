import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, show } from '@/routes/twins';
import { index as alertLog } from '@/routes/twins/alerts';
import { store as acknowledge } from '@/routes/twins/alerts/acknowledgement';
import type { BreadcrumbItem } from '@/types';

type LoggedAlert = {
    id: string;
    rule: string;
    asset: string;
    severity: 'info' | 'warning' | 'critical';
    message: string;
    value: number;
    raisedAt: string;
    clearedAt: string | null;
    acknowledgedAt: string | null;
    acknowledgedBy: string | null;
};

type Props = {
    twin: { id: string; name: string };
    alerts: LoggedAlert[];
    pages: { previous: string | null; next: string | null };
    show: 'open' | 'all';
    hasGateway: boolean;
    canAcknowledge: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Twins', href: index() }];

const when = (iso: string) => new Date(iso).toLocaleString();

/** How long an alert was open, or has been: "2 h 5 min", "12 min", "under a minute". */
function lasted(from: string, to: string | null): string {
    const minutes = Math.floor(
        ((to ? Date.parse(to) : Date.now()) - Date.parse(from)) / 60_000,
    );
    if (minutes < 1) return 'under a minute';
    const hours = Math.floor(minutes / 60);
    return hours > 0
        ? `${hours} h${minutes % 60 ? ` ${minutes % 60} min` : ''}`
        : `${minutes} min`;
}

export default function TwinAlerts({
    twin,
    alerts,
    pages,
    show: showing,
    hasGateway,
    canAcknowledge,
}: Props) {
    return (
        <>
            <Head title={`Alerts · ${twin.name}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Alert log"
                        description={`Every alert ${twin.name}'s gateway has raised, newest first, and who acknowledged it.`}
                    />
                    <div className="flex gap-2">
                        <div
                            className="flex rounded-md border p-0.5"
                            role="radiogroup"
                            aria-label="Show"
                        >
                            {(['all', 'open'] as const).map((option) => (
                                <Link
                                    key={option}
                                    role="radio"
                                    aria-checked={showing === option}
                                    href={alertLog.url(twin.id, {
                                        query:
                                            option === 'open'
                                                ? { show: 'open' }
                                                : {},
                                    })}
                                    className={`rounded px-3 py-1 text-sm ${showing === option ? 'bg-accent font-medium' : 'text-muted-foreground'}`}
                                >
                                    {option === 'all' ? 'All' : 'Open'}
                                </Link>
                            ))}
                        </div>
                        <Button variant="outline" asChild>
                            <Link href={show(twin.id)}>Open twin</Link>
                        </Button>
                    </div>
                </div>

                {alerts.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        {!hasGateway
                            ? 'Alerts are logged while a gateway runs the twin. Connect one from the Layout panel; without it, alerts only show while the twin is open.'
                            : showing === 'open'
                              ? 'Nothing is open.'
                              : 'Nothing has been raised yet.'}
                    </div>
                ) : (
                    <ul
                        className="divide-y rounded-xl border"
                        aria-label="Alerts"
                    >
                        {alerts.map((alert) => (
                            <li
                                key={alert.id}
                                className="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div className="flex min-w-0 flex-col gap-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant={
                                                alert.severity === 'critical'
                                                    ? 'destructive'
                                                    : alert.severity ===
                                                        'warning'
                                                      ? 'default'
                                                      : 'secondary'
                                            }
                                        >
                                            {alert.severity}
                                        </Badge>
                                        {alert.clearedAt === null && (
                                            <Badge variant="outline">
                                                Open
                                            </Badge>
                                        )}
                                        <span className="font-medium">
                                            {alert.message}
                                        </span>
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        Raised {when(alert.raisedAt)}
                                        {alert.clearedAt
                                            ? `, cleared ${when(alert.clearedAt)} after ${lasted(alert.raisedAt, alert.clearedAt)}`
                                            : `, open for ${lasted(alert.raisedAt, null)}`}
                                        {' · '}
                                        {alert.asset} · rule {alert.rule} ·
                                        value {Number(alert.value.toFixed(2))}
                                    </span>
                                </div>
                                <div className="shrink-0 text-sm">
                                    {alert.acknowledgedAt ? (
                                        <span className="text-muted-foreground">
                                            Acknowledged by{' '}
                                            {alert.acknowledgedBy ??
                                                'a former member'}{' '}
                                            · {when(alert.acknowledgedAt)}
                                        </span>
                                    ) : (
                                        canAcknowledge && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        acknowledge.url([
                                                            twin.id,
                                                            alert.id,
                                                        ]),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Acknowledge
                                            </Button>
                                        )
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {(pages.previous || pages.next) && (
                    <div className="flex justify-between">
                        {pages.previous ? (
                            <Button variant="outline" asChild>
                                <Link href={pages.previous}>Newer</Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        {pages.next && (
                            <Button variant="outline" asChild>
                                <Link href={pages.next}>Older</Link>
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

TwinAlerts.layout = [AppLayout, { breadcrumbs }];
