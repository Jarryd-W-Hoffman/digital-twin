import { router, useForm } from '@inertiajs/react';
import { Cpu, KeyRound, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy as destroyDevice,
    store as storeDevice,
} from '@/routes/projects/devices';
import { store as resetPassword } from '@/routes/projects/devices/password';

export type ProjectDevice = {
    id: string;
    name: string;
    username: string;
    lastSeenAt: string | null;
};

export type ProjectMqtt = { brokerUrl: string; topicPrefix: string };

type Credentials = { deviceId: string; username: string; password: string };

/** The broker's host and port, for the example command. */
function broker(url: string): { host: string; port: string } {
    try {
        const parsed = new URL(url);
        return { host: parsed.hostname, port: parsed.port || '1883' };
    } catch {
        return { host: url, port: '1883' };
    }
}

/**
 * The devices allowed to publish to the project's MQTT topics. A new or reset
 * password is flashed once by the server and shown here.
 */
export default function ProjectDevices({
    projectId,
    devices,
    mqtt,
    canUpdate,
}: {
    projectId: string;
    devices: ProjectDevice[];
    mqtt: ProjectMqtt;
    canUpdate: boolean;
}) {
    const form = useForm({ name: '' });
    const [issued, setIssued] = useState<Credentials | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const credentials = event.detail.flash.deviceCredentials;
                if (credentials) setIssued(credentials);
            }),
        [],
    );

    const { host, port } = broker(mqtt.brokerUrl);
    const shown = issued
        ? devices.find((device) => device.id === issued.deviceId)
        : undefined;
    const command = issued
        ? `mosquitto_pub -h ${host} -p ${port} -u ${issued.username} -P ${issued.password} -t '${mqtt.topicPrefix}site/sensor/temp' -m 21.5`
        : '';

    return (
        <section className="grid gap-3" aria-labelledby="devices-heading">
            <div>
                <h2 id="devices-heading" className="text-lg font-medium">
                    Devices
                </h2>
                <p className="text-sm text-muted-foreground">
                    Sensors and controllers that publish readings to the broker
                    at <code>{mqtt.brokerUrl}</code>. Each logs in with its own
                    password and may publish only below{' '}
                    <code>{mqtt.topicPrefix}</code>. Map their topics to metrics
                    in a twin's Layout panel.
                </p>
            </div>

            {canUpdate && (
                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeDevice.url(projectId), {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid min-w-48 flex-1 gap-2">
                        <Label htmlFor="device-name">Device name</Label>
                        <Input
                            id="device-name"
                            required
                            maxLength={100}
                            placeholder="Freezer probe 1"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Add device
                    </Button>
                </form>
            )}

            {issued && (
                <div
                    className="grid gap-2 rounded-xl border border-amber-500/50 bg-amber-500/5 p-4 text-sm"
                    role="status"
                    aria-label="Device login"
                >
                    <p>
                        <strong>{shown?.name ?? 'Device'}</strong> logs in as{' '}
                        <code>{issued.username}</code> with this password. Copy
                        it now; it won't be shown again.
                    </p>
                    <Input
                        readOnly
                        aria-label="Device password"
                        value={issued.password}
                        onFocus={(e) => e.target.select()}
                    />
                    <p className="text-muted-foreground">
                        To try it, publish a reading:
                    </p>
                    <textarea
                        className="min-h-16 rounded-md border bg-muted p-2 font-mono text-xs"
                        aria-label="Example command"
                        readOnly
                        value={command}
                        onFocus={(e) => e.target.select()}
                    />
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                void navigator.clipboard?.writeText(
                                    issued.password,
                                )
                            }
                        >
                            Copy password
                        </Button>
                    </div>
                </div>
            )}

            {devices.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    No devices yet.
                </div>
            ) : (
                <ul className="divide-y rounded-xl border" aria-label="Devices">
                    {devices.map((device) => (
                        <li
                            key={device.id}
                            className="flex items-start gap-3 p-3 text-sm"
                        >
                            <Cpu className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">
                                    {device.name}
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    {device.username} ·{' '}
                                    {device.lastSeenAt
                                        ? `last connected ${new Date(device.lastSeenAt).toLocaleString()}`
                                        : 'never connected'}
                                </span>
                            </span>
                            {canUpdate && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Reset the password of ${device.name}`}
                                        onClick={() =>
                                            confirm(
                                                `Reset the password of ${device.name}? It is shut out until it uses the new one.`,
                                            ) &&
                                            router.post(
                                                resetPassword.url([
                                                    projectId,
                                                    device.id,
                                                ]),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <KeyRound />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Delete ${device.name}`}
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Delete ${device.name}? The broker stops accepting it within a minute.`,
                                                )
                                            ) {
                                                if (
                                                    issued?.deviceId ===
                                                    device.id
                                                )
                                                    setIssued(null);
                                                router.delete(
                                                    destroyDevice.url([
                                                        projectId,
                                                        device.id,
                                                    ]),
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
