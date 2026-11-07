import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    destroy as revokeToken,
    store as issueToken,
} from '@/routes/twins/gateway-token';

export type GatewayStatus = {
    online: boolean;
    url: string | null;
    seenAt: string | null;
    mqtt: 'connected' | 'disconnected' | null;
    ticket: string | null;
} | null;

/**
 * The twin's gateway: whether one is connected, and its token. A new token is
 * flashed once by the server and shown here with the command to run it.
 */
export function GatewayPanel({
    twinId,
    gateway,
}: {
    twinId: string;
    gateway: GatewayStatus;
}) {
    const [token, setToken] = useState<string | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const issued = event.detail.flash.gatewayToken;
                if (issued) setToken(issued);
            }),
        [],
    );

    const command = token
        ? `MERIDIAN_URL=${window.location.origin} MERIDIAN_GATEWAY_TOKEN=${token} npm run gateway`
        : '';

    return (
        <section>
            <h3>Gateway</h3>
            <p className="muted gateway-status" aria-live="polite">
                {gateway === null
                    ? 'No token yet. A gateway runs the twin as a service and takes sensor readings.'
                    : gateway.online
                      ? `Online at ${gateway.url}${gateway.mqtt === 'connected' ? ', connected to the broker' : gateway.mqtt === 'disconnected' ? ', but not connected to the broker' : ''}`
                      : gateway.seenAt
                        ? `Offline, last seen ${new Date(gateway.seenAt).toLocaleString()}`
                        : 'Waiting for a gateway to connect with this token.'}
            </p>
            {token && (
                <div className="gateway-token">
                    <p className="muted">
                        Copy this now; it won't be shown again. The same token
                        authorises readings at <code>POST /ingest</code>.
                    </p>
                    <textarea
                        aria-label="Gateway command"
                        readOnly
                        rows={4}
                        value={command}
                        onFocus={(e) => e.target.select()}
                    />
                    <button
                        className="ghost"
                        onClick={() =>
                            void navigator.clipboard?.writeText(command)
                        }
                    >
                        Copy command
                    </button>
                </div>
            )}
            <div className="editor-actions">
                <button
                    onClick={() => {
                        if (
                            gateway === null ||
                            window.confirm(
                                'Issue a new token? The running gateway will stop being accepted.',
                            )
                        )
                            router.post(
                                issueToken.url(twinId),
                                {},
                                { preserveScroll: true },
                            );
                    }}
                >
                    {gateway === null ? 'Issue token' : 'Rotate token'}
                </button>
                {gateway !== null && (
                    <button
                        className="link"
                        onClick={() => {
                            if (
                                window.confirm(
                                    'Revoke the token? The gateway will be cut off.',
                                )
                            ) {
                                setToken(null);
                                router.delete(revokeToken.url(twinId), {
                                    preserveScroll: true,
                                });
                            }
                        }}
                    >
                        Revoke
                    </button>
                )}
            </div>
        </section>
    );
}
