import { router, useForm } from '@inertiajs/react';
import { BellRing, Mail, Send, Trash2, Webhook } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy as destroyChannel,
    store as storeChannel,
    test as testChannel,
} from '@/routes/projects/notification-channels';

export type ChannelKind = 'email' | 'webhook' | 'slack' | 'teams';
type Severity = 'info' | 'warning' | 'critical';

export type ProjectNotificationChannel = {
    id: string;
    name: string;
    kind: ChannelKind;
    /** Where it sends; null for members who can't edit the project. */
    target: string | null;
    minSeverity: Severity;
    notifyCleared: boolean;
    /** Told only of alerts still unacknowledged this long; null when told at once. */
    escalateAfterMinutes: number | null;
    quietFrom: string | null;
    quietUntil: string | null;
    timezone: string;
    lastSentAt: string | null;
    lastError: string | null;
};

const KINDS: Record<
    ChannelKind,
    { label: string; target: string; placeholder: string }
> = {
    email: {
        label: 'Email',
        target: 'Email addresses',
        placeholder: 'ops@example.com, oncall@example.com',
    },
    slack: {
        label: 'Slack',
        target: 'Slack webhook URL',
        placeholder: 'https://hooks.slack.com/services/…',
    },
    teams: {
        label: 'Microsoft Teams',
        target: 'Teams workflow URL',
        placeholder: 'https://….logic.azure.com/workflows/…',
    },
    webhook: {
        label: 'Webhook',
        target: 'Webhook URL',
        placeholder: 'https://example.com/meridian/alerts',
    },
};

const SEVERITIES: Record<Severity, string> = {
    info: 'Everything',
    warning: 'Warnings and critical',
    critical: 'Critical only',
};

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

const browserTimezone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
};

/**
 * Where the project's alerts are sent when a twin's gateway raises or
 * clears them: email, Slack, Teams or a signed webhook, each with the
 * severities it takes and hours it keeps quiet for all but critical alerts.
 * An escalation channel hears only of alerts nobody acknowledged in time.
 */
export default function ProjectNotificationChannels({
    projectId,
    channels,
    canUpdate,
}: {
    projectId: string;
    channels: ProjectNotificationChannel[];
    canUpdate: boolean;
}) {
    const form = useForm({
        name: '',
        kind: 'email' as ChannelKind,
        target: '',
        min_severity: 'warning' as Severity,
        notify_cleared: true,
        escalate_after_minutes: '',
        quiet_from: '',
        quiet_until: '',
        timezone: browserTimezone(),
    });
    const [secret, setSecret] = useState<{
        channelId: string;
        secret: string;
    } | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const issued = event.detail.flash.channelSecret;
                if (issued) setSecret(issued);
            }),
        [],
    );

    const kind = KINDS[form.data.kind];

    return (
        <section className="grid gap-3" aria-labelledby="notifications-heading">
            <div>
                <h2 id="notifications-heading" className="text-lg font-medium">
                    Alert notifications
                </h2>
                <p className="text-sm text-muted-foreground">
                    Where alerts from this project's twins are sent while a
                    gateway runs them. During a channel's quiet hours, only
                    critical alerts get through. An escalation channel is told
                    only when nobody acknowledges an alert in time.
                </p>
            </div>

            {canUpdate && (
                <form
                    className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
                    aria-label="Add a notification channel"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeChannel.url(projectId), {
                            preserveScroll: true,
                            onSuccess: () => form.reset('name', 'target'),
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="channel-name">Channel name</Label>
                        <Input
                            id="channel-name"
                            required
                            maxLength={100}
                            placeholder="Night shift"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="channel-kind">Send by</Label>
                        <select
                            id="channel-kind"
                            className={selectClass}
                            value={form.data.kind}
                            onChange={(e) =>
                                form.setData(
                                    'kind',
                                    e.target.value as ChannelKind,
                                )
                            }
                        >
                            {Object.entries(KINDS).map(([value, k]) => (
                                <option key={value} value={value}>
                                    {k.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.kind} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="channel-target">{kind.target}</Label>
                        <Input
                            id="channel-target"
                            required
                            maxLength={2000}
                            autoComplete="off"
                            placeholder={kind.placeholder}
                            value={form.data.target}
                            onChange={(e) =>
                                form.setData('target', e.target.value)
                            }
                        />
                        <InputError message={form.errors.target} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="channel-severity">Send</Label>
                        <select
                            id="channel-severity"
                            className={selectClass}
                            value={form.data.min_severity}
                            onChange={(e) =>
                                form.setData(
                                    'min_severity',
                                    e.target.value as Severity,
                                )
                            }
                        >
                            {Object.entries(SEVERITIES).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                        <InputError message={form.errors.min_severity} />
                    </div>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.notify_cleared}
                            onChange={(e) =>
                                form.setData('notify_cleared', e.target.checked)
                            }
                        />
                        Also say when an alert clears
                    </label>
                    <div className="grid gap-2">
                        <Label htmlFor="channel-when">When</Label>
                        <select
                            id="channel-when"
                            className={selectClass}
                            value={
                                form.data.escalate_after_minutes === ''
                                    ? 'raised'
                                    : 'escalate'
                            }
                            onChange={(e) =>
                                form.setData(
                                    'escalate_after_minutes',
                                    e.target.value === 'raised' ? '' : '15',
                                )
                            }
                        >
                            <option value="raised">
                                As soon as it's raised
                            </option>
                            <option value="escalate">
                                If nobody acknowledges it in time
                            </option>
                        </select>
                    </div>
                    {form.data.escalate_after_minutes !== '' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="channel-escalate">
                                Minutes to wait
                            </Label>
                            <Input
                                id="channel-escalate"
                                type="number"
                                required
                                min={1}
                                max={1440}
                                value={form.data.escalate_after_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'escalate_after_minutes',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.escalate_after_minutes}
                            />
                        </div>
                    ) : (
                        <span />
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="channel-quiet-from">
                                Quiet from (optional)
                            </Label>
                            <Input
                                id="channel-quiet-from"
                                type="time"
                                value={form.data.quiet_from}
                                onChange={(e) =>
                                    form.setData('quiet_from', e.target.value)
                                }
                            />
                            <InputError message={form.errors.quiet_from} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="channel-quiet-until">
                                Quiet until
                            </Label>
                            <Input
                                id="channel-quiet-until"
                                type="time"
                                value={form.data.quiet_until}
                                onChange={(e) =>
                                    form.setData('quiet_until', e.target.value)
                                }
                            />
                            <InputError message={form.errors.quiet_until} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="channel-timezone">Time zone</Label>
                        <Input
                            id="channel-timezone"
                            required
                            maxLength={64}
                            value={form.data.timezone}
                            onChange={(e) =>
                                form.setData('timezone', e.target.value)
                            }
                        />
                        <InputError message={form.errors.timezone} />
                    </div>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            Add channel
                        </Button>
                    </div>
                </form>
            )}

            {channels.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    No channels yet: alerts only show in the twins and their
                    alert logs.
                </div>
            ) : (
                <ul
                    className="divide-y rounded-xl border"
                    aria-label="Notification channels"
                >
                    {channels.map((channel) => {
                        const Icon =
                            channel.kind === 'email'
                                ? Mail
                                : channel.kind === 'webhook'
                                  ? Webhook
                                  : BellRing;
                        return (
                            <li
                                key={channel.id}
                                className="flex flex-col gap-2 p-3 text-sm"
                            >
                                <div className="flex items-start gap-3">
                                    <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {channel.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {KINDS[channel.kind].label}
                                            {channel.target
                                                ? ` to ${channel.target}`
                                                : ''}{' '}
                                            ·{' '}
                                            {SEVERITIES[
                                                channel.minSeverity
                                            ].toLowerCase()}
                                            {channel.escalateAfterMinutes !==
                                            null
                                                ? ` not acknowledged after ${channel.escalateAfterMinutes} min`
                                                : ''}
                                            {channel.notifyCleared
                                                ? ', and when they clear'
                                                : ''}
                                            {channel.quietFrom &&
                                                ` · quiet ${channel.quietFrom}–${channel.quietUntil} ${channel.timezone}, except critical`}
                                        </span>
                                        <span
                                            className={`block text-xs ${channel.lastError ? 'text-destructive' : 'text-muted-foreground'}`}
                                        >
                                            {channel.lastError
                                                ? `Last attempt failed: ${channel.lastError}`
                                                : channel.lastSentAt
                                                  ? `Last sent ${new Date(channel.lastSentAt).toLocaleString()}`
                                                  : 'Nothing sent yet'}
                                        </span>
                                    </span>
                                    {canUpdate && (
                                        <>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        testChannel.url([
                                                            projectId,
                                                            channel.id,
                                                        ]),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Send /> Send test
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={`Delete ${channel.name}`}
                                                onClick={() =>
                                                    confirm(
                                                        `Delete ${channel.name}? Alerts stop going there.`,
                                                    ) &&
                                                    router.delete(
                                                        destroyChannel.url([
                                                            projectId,
                                                            channel.id,
                                                        ]),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </>
                                    )}
                                </div>
                                {secret?.channelId === channel.id && (
                                    <div className="rounded-md bg-muted p-3 text-xs">
                                        <p className="font-medium">
                                            Signing secret
                                        </p>
                                        <p className="text-muted-foreground">
                                            Each request carries an
                                            X-Meridian-Signature header: sha256=
                                            and the HMAC-SHA256 of the body with
                                            this secret. Copy it now.
                                        </p>
                                        <code
                                            className="mt-1 block break-all"
                                            aria-label="Signing secret"
                                        >
                                            {secret.secret}
                                        </code>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
