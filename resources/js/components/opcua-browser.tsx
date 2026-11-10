import { useEffect, useMemo, useState } from 'react';
import type { OpcUaBrowsedNode } from '@/twin/core/protocol';
import { OpcUaBrowser } from '@/twin/sources/opcua-browser';

type Level = { node: string | undefined; name: string };

/**
 * Browses a project's OPC UA server through the twin's gateway, one level at
 * a time, to find a node to map. Opening a server is also a connection test:
 * the gateway connects with the server's settings and says why it couldn't.
 */
export function OpcUaNodeBrowser({
    servers,
    gateway,
    onPick,
}: {
    servers: string[];
    /** The gateway's socket and a ticket for it, while it's online. */
    gateway: { url: string; ticket: string } | null;
    onPick: (server: string, node: OpcUaBrowsedNode) => void;
}) {
    const [server, setServer] = useState(servers[0] ?? '');
    const [path, setPath] = useState<Level[]>([]);
    const [children, setChildren] = useState<OpcUaBrowsedNode[] | null>(null);
    const [more, setMore] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const socketUrl = useMemo(() => {
        if (!gateway) return null;
        const url = new URL(gateway.url);
        url.searchParams.set('ticket', gateway.ticket);
        return url.toString();
    }, [gateway?.url, gateway?.ticket]);
    const browser = useMemo(
        () => (socketUrl ? new OpcUaBrowser(socketUrl) : null),
        [socketUrl],
    );
    useEffect(() => () => browser?.close(), [browser]);

    const open = async (to: Level[]) => {
        if (!browser) return;
        setBusy(true);
        setError(null);
        try {
            const result = await browser.browse(server, to.at(-1)?.node);
            setPath(to);
            setChildren(result.children);
            setMore(result.more);
        } catch (failure) {
            setError(
                `Couldn't browse ${server}: ${failure instanceof Error ? failure.message : String(failure)}`,
            );
        } finally {
            setBusy(false);
        }
    };

    if (servers.length === 0) return null;

    return (
        <div className="opcua-browser" aria-label="OPC UA browser" role="group">
            <h4>Browse a server</h4>
            {!browser && (
                <p className="muted hint">
                    The twin's gateway connects to the servers, so start it to
                    browse them.
                </p>
            )}
            <div className="editor-actions">
                <label>
                    <span>Look in</span>
                    <select
                        value={server}
                        onChange={(e) => {
                            setServer(e.target.value);
                            setChildren(null);
                            setPath([]);
                            setError(null);
                        }}
                    >
                        {servers.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </label>
                <button
                    disabled={!browser || busy}
                    onClick={() =>
                        void open([{ node: undefined, name: 'Objects' }])
                    }
                >
                    {busy ? 'Browsing…' : 'Browse'}
                </button>
            </div>
            {error !== null && (
                <p className="editor-error" role="alert">
                    {error}
                </p>
            )}
            {children !== null && (
                <>
                    <nav aria-label="Browse path" className="muted">
                        {path.map((level, i) => (
                            <span key={i}>
                                {i > 0 && ' › '}
                                <button
                                    className="link"
                                    disabled={busy || i === path.length - 1}
                                    onClick={() =>
                                        void open(path.slice(0, i + 1))
                                    }
                                >
                                    {level.name}
                                </button>
                            </span>
                        ))}
                    </nav>
                    {children.length === 0 ? (
                        <p className="muted hint">Nothing here.</p>
                    ) : (
                        <ul
                            className="editor-layers"
                            aria-label={`What's on ${server}`}
                        >
                            {children.map((child) => (
                                <li key={child.node}>
                                    {child.kind === 'object' ? (
                                        <button
                                            className="link"
                                            disabled={busy}
                                            onClick={() =>
                                                void open([
                                                    ...path,
                                                    {
                                                        node: child.node,
                                                        name: child.name,
                                                    },
                                                ])
                                            }
                                        >
                                            {child.name} ›
                                        </button>
                                    ) : (
                                        <>
                                            <strong>{child.name}</strong>{' '}
                                            <span className="muted">
                                                {child.dataType}
                                                {child.value !== null &&
                                                    child.value !== undefined &&
                                                    ` = ${String(child.value)}`}
                                                {child.writable && ', writable'}
                                            </span>{' '}
                                            <button
                                                className="link"
                                                aria-label={`Map ${child.node}`}
                                                onClick={() =>
                                                    onPick(server, child)
                                                }
                                            >
                                                Map
                                            </button>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {more && (
                        <p className="muted hint">
                            Only the first 200 are shown.
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
