import { router, useForm } from '@inertiajs/react';
import { Gauge, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy as destroyDevice,
    store as storeDevice,
} from '@/routes/projects/modbus-devices';

export type ProjectModbusDevice = {
    id: string;
    name: string;
    host: string;
    port: number;
    unitId: number;
};

/**
 * The Modbus TCP devices (meters, drives, PLCs) the project's gateways poll.
 * Twins map their registers to metrics by the device's name.
 */
export default function ProjectModbusDevices({
    projectId,
    devices,
    canUpdate,
}: {
    projectId: string;
    devices: ProjectModbusDevice[];
    canUpdate: boolean;
}) {
    const form = useForm({
        name: '',
        host: '',
        port: '502',
        unit_id: '1',
    });

    return (
        <section className="grid gap-3" aria-labelledby="modbus-heading">
            <div>
                <h2 id="modbus-heading" className="text-lg font-medium">
                    Modbus devices
                </h2>
                <p className="text-sm text-muted-foreground">
                    Meters, drives and PLCs the twins' gateways poll over Modbus
                    TCP. Map their registers to metrics in a twin's Layout
                    panel; the gateway asks for them at the rate you choose.
                </p>
            </div>

            {canUpdate && (
                <form
                    className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
                    aria-label="Add a Modbus device"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeDevice.url(projectId), {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="modbus-name">Device name</Label>
                        <Input
                            id="modbus-name"
                            required
                            maxLength={100}
                            placeholder="Main meter"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="modbus-host">Host</Label>
                        <Input
                            id="modbus-host"
                            required
                            maxLength={255}
                            placeholder="192.168.1.20"
                            value={form.data.host}
                            onChange={(e) =>
                                form.setData('host', e.target.value)
                            }
                        />
                        <InputError message={form.errors.host} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="modbus-port">Port</Label>
                        <Input
                            id="modbus-port"
                            type="number"
                            required
                            min={1}
                            max={65535}
                            value={form.data.port}
                            onChange={(e) =>
                                form.setData('port', e.target.value)
                            }
                        />
                        <InputError message={form.errors.port} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="modbus-unit">Unit id</Label>
                        <Input
                            id="modbus-unit"
                            type="number"
                            required
                            min={0}
                            max={255}
                            value={form.data.unit_id}
                            onChange={(e) =>
                                form.setData('unit_id', e.target.value)
                            }
                        />
                        <InputError message={form.errors.unit_id} />
                    </div>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            Add device
                        </Button>
                    </div>
                </form>
            )}

            {devices.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    No Modbus devices yet.
                </div>
            ) : (
                <ul
                    className="divide-y rounded-xl border"
                    aria-label="Modbus devices"
                >
                    {devices.map((device) => (
                        <li
                            key={device.id}
                            className="flex items-start gap-3 p-3 text-sm"
                        >
                            <Gauge className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">
                                    {device.name}
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    {device.host}:{device.port} · unit{' '}
                                    {device.unitId}
                                </span>
                            </span>
                            {canUpdate && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Delete ${device.name}`}
                                    onClick={() =>
                                        confirm(
                                            `Delete ${device.name}? Gateways stop polling it, and twins' mappings to it are skipped.`,
                                        ) &&
                                        router.delete(
                                            destroyDevice.url([
                                                projectId,
                                                device.id,
                                            ]),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
