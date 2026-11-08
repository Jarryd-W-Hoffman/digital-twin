import { router, useForm } from '@inertiajs/react';
import { Factory, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy as destroyServer,
    store as storeServer,
} from '@/routes/projects/opcua-servers';

export type SecurityMode = 'none' | 'sign' | 'signAndEncrypt';

export type ProjectOpcUaServer = {
    id: string;
    name: string;
    endpoint: string;
    securityMode: SecurityMode;
    securityPolicy: string;
    username: string | null;
};

const MODES: Record<SecurityMode, string> = {
    none: 'None',
    sign: 'Sign',
    signAndEncrypt: 'Sign and encrypt',
};

const POLICIES = [
    'Basic256Sha256',
    'Aes128_Sha256_RsaOaep',
    'Aes256_Sha256_RsaPss',
];

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

/**
 * The OPC UA servers (PLCs, SCADA) the project's gateways read from. Twins
 * map their nodes to metrics by the server's name. Passwords go to the
 * project's gateways only and are never shown again.
 */
export default function ProjectOpcUaServers({
    projectId,
    servers,
    canUpdate,
}: {
    projectId: string;
    servers: ProjectOpcUaServer[];
    canUpdate: boolean;
}) {
    const form = useForm({
        name: '',
        endpoint: '',
        security_mode: 'none' as SecurityMode,
        security_policy: 'None',
        username: '',
        password: '',
    });

    return (
        <section className="grid gap-3" aria-labelledby="opcua-heading">
            <div>
                <h2 id="opcua-heading" className="text-lg font-medium">
                    OPC UA servers
                </h2>
                <p className="text-sm text-muted-foreground">
                    PLCs and SCADA systems the twins' gateways read from. Map
                    their nodes to metrics in a twin's Layout panel; the gateway
                    monitors them and records each new value.
                </p>
            </div>

            {canUpdate && (
                <form
                    className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
                    aria-label="Add an OPC UA server"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeServer.url(projectId), {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="opcua-name">Server name</Label>
                        <Input
                            id="opcua-name"
                            required
                            maxLength={100}
                            placeholder="Plant PLC"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="opcua-endpoint">Endpoint</Label>
                        <Input
                            id="opcua-endpoint"
                            required
                            maxLength={255}
                            placeholder="opc.tcp://plc.local:4840"
                            value={form.data.endpoint}
                            onChange={(e) =>
                                form.setData('endpoint', e.target.value)
                            }
                        />
                        <InputError message={form.errors.endpoint} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="opcua-mode">Security</Label>
                        <select
                            id="opcua-mode"
                            className={selectClass}
                            value={form.data.security_mode}
                            onChange={(e) => {
                                const mode = e.target.value as SecurityMode;
                                form.setData((data) => ({
                                    ...data,
                                    security_mode: mode,
                                    security_policy:
                                        mode === 'none'
                                            ? 'None'
                                            : data.security_policy === 'None'
                                              ? POLICIES[0]
                                              : data.security_policy,
                                }));
                            }}
                        >
                            {Object.entries(MODES).map(([mode, label]) => (
                                <option key={mode} value={mode}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.security_mode} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="opcua-policy">Security policy</Label>
                        <select
                            id="opcua-policy"
                            className={selectClass}
                            disabled={form.data.security_mode === 'none'}
                            value={form.data.security_policy}
                            onChange={(e) =>
                                form.setData('security_policy', e.target.value)
                            }
                        >
                            {form.data.security_mode === 'none' ? (
                                <option value="None">None</option>
                            ) : (
                                POLICIES.map((policy) => (
                                    <option key={policy} value={policy}>
                                        {policy}
                                    </option>
                                ))
                            )}
                        </select>
                        <InputError message={form.errors.security_policy} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="opcua-username">
                            Username (optional)
                        </Label>
                        <Input
                            id="opcua-username"
                            maxLength={100}
                            autoComplete="off"
                            value={form.data.username}
                            onChange={(e) =>
                                form.setData('username', e.target.value)
                            }
                        />
                        <InputError message={form.errors.username} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="opcua-password">Password</Label>
                        <Input
                            id="opcua-password"
                            type="password"
                            maxLength={255}
                            autoComplete="new-password"
                            disabled={form.data.username.trim() === ''}
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            Add server
                        </Button>
                    </div>
                </form>
            )}

            {servers.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    No OPC UA servers yet.
                </div>
            ) : (
                <ul
                    className="divide-y rounded-xl border"
                    aria-label="OPC UA servers"
                >
                    {servers.map((server) => (
                        <li
                            key={server.id}
                            className="flex items-start gap-3 p-3 text-sm"
                        >
                            <Factory className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">
                                    {server.name}
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    {server.endpoint} ·{' '}
                                    {server.securityMode === 'none'
                                        ? 'unsecured'
                                        : `${MODES[server.securityMode].toLowerCase()}, ${server.securityPolicy}`}{' '}
                                    ·{' '}
                                    {server.username
                                        ? `as ${server.username}`
                                        : 'anonymous'}
                                </span>
                            </span>
                            {canUpdate && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Delete ${server.name}`}
                                    onClick={() =>
                                        confirm(
                                            `Delete ${server.name}? Gateways disconnect from it, and twins' mappings to it are skipped.`,
                                        ) &&
                                        router.delete(
                                            destroyServer.url([
                                                projectId,
                                                server.id,
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
