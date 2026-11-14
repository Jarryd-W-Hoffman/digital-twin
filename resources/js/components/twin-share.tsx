import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import TwinShareLinkController from '@/actions/App/Http/Controllers/TwinShareLinkController';
import { useClipboard } from '@/hooks/use-clipboard';

export type ShareLinkSummary = {
    id: string;
    name: string;
    hint: string;
    live: boolean;
    createdBy: string | null;
    createdAt: string;
    expiresAt: string;
    expired: boolean;
    lastViewedAt: string | null;
};

const LIFETIMES = [
    { value: 1, label: '1 day' },
    { value: 7, label: '7 days' },
    { value: 30, label: '30 days' },
    { value: 90, label: '90 days' },
];

const day = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/**
 * Links that show the twin, read only, to people outside the organisation,
 * for admins and owners. A new link is flashed once by the server.
 */
export function TwinShare({
    twinId,
    links,
    onClose,
}: {
    twinId: string;
    links: ShareLinkSummary[];
    onClose: () => void;
}) {
    const form = useForm({ name: '', expires_in_days: 30, live: true });
    const [issued, setIssued] = useState<{
        linkId: string;
        url: string;
    } | null>(null);
    const [copied, copy] = useClipboard();

    useEffect(
        () =>
            router.on('flash', (event) => {
                const link = event.detail.flash.shareLink;
                if (link) setIssued(link);
            }),
        [],
    );

    return (
        <aside className="panel editor" aria-label="Share">
            <div className="editor-head">
                <h2>Share</h2>
                <button
                    className="ghost"
                    onClick={onClose}
                    aria-label="Close share"
                >
                    ✕
                </button>
            </div>

            <section>
                <p className="muted hint">
                    A link shows this twin to anyone who has it, without an
                    account: the scene from its opening view and, if you choose,
                    its live readings and history. Nobody can change anything
                    through it, and it stops working when it expires or you
                    revoke it.
                </p>
                {issued && (
                    <div
                        className="editor-form"
                        role="status"
                        aria-label="New share link"
                    >
                        <p className="hint">
                            Copy the link now. It won't be shown again.
                        </p>
                        <input
                            aria-label="Share link"
                            readOnly
                            value={issued.url}
                            onFocus={(e) => e.target.select()}
                        />
                        <div className="editor-actions">
                            <button
                                className="primary"
                                onClick={() => void copy(issued.url)}
                            >
                                {copied === issued.url ? 'Copied' : 'Copy link'}
                            </button>
                        </div>
                    </div>
                )}
            </section>

            <section aria-label="Links">
                <h3>Links</h3>
                {links.length === 0 ? (
                    <p className="editor-hint">Not shared yet.</p>
                ) : (
                    <ul className="view-list">
                        {links.map((link) => (
                            <li
                                key={link.id}
                                className="view-row"
                                aria-label={link.name}
                            >
                                <div className="view-open">
                                    <span className="view-name">
                                        {link.name}
                                    </span>
                                    <span className="issue-meta">
                                        …{link.hint} ·{' '}
                                        {link.live ? 'Live' : 'Scene only'} ·{' '}
                                        {link.expired
                                            ? `Expired ${day(link.expiresAt)}`
                                            : `Expires ${day(link.expiresAt)}`}{' '}
                                        ·{' '}
                                        {link.lastViewedAt
                                            ? `Last opened ${day(link.lastViewedAt)}`
                                            : 'Not opened yet'}
                                        {link.createdBy &&
                                            ` · Made by ${link.createdBy}`}
                                    </span>
                                </div>
                                <div className="view-actions">
                                    <button
                                        className="ghost"
                                        onClick={() =>
                                            confirm(
                                                `Revoke ${link.name}? Anyone with it can no longer open the twin.`,
                                            ) &&
                                            router.delete(
                                                TwinShareLinkController.destroy.url(
                                                    [twinId, link.id],
                                                ),
                                                {
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                    only: ['shareLinks'],
                                                    onSuccess: () => {
                                                        if (
                                                            issued?.linkId ===
                                                            link.id
                                                        )
                                                            setIssued(null);
                                                    },
                                                },
                                            )
                                        }
                                    >
                                        Revoke
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section>
                <h3>Make a link</h3>
                <form
                    className="editor-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.submit(TwinShareLinkController.store(twinId), {
                            preserveScroll: true,
                            preserveState: true,
                            only: ['shareLinks'],
                            onSuccess: () => form.reset('name'),
                        });
                    }}
                >
                    <label className="editor-field">
                        <span>Who it's for</span>
                        <input
                            value={form.data.name}
                            maxLength={100}
                            required
                            placeholder="Harbour board walkthrough"
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </label>
                    {form.errors.name && (
                        <p className="editor-error" role="alert">
                            {form.errors.name}
                        </p>
                    )}
                    <label className="editor-field">
                        <span>Expires in</span>
                        <select
                            value={form.data.expires_in_days}
                            onChange={(e) =>
                                form.setData(
                                    'expires_in_days',
                                    Number(e.target.value),
                                )
                            }
                        >
                            {LIFETIMES.map((l) => (
                                <option key={l.value} value={l.value}>
                                    {l.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="editor-check">
                        <input
                            type="checkbox"
                            checked={form.data.live}
                            onChange={(e) =>
                                form.setData('live', e.target.checked)
                            }
                        />
                        <span>Show live readings and history</span>
                    </label>
                    <div className="editor-actions">
                        <button
                            type="submit"
                            className="primary"
                            disabled={form.processing}
                        >
                            Make link
                        </button>
                    </div>
                </form>
            </section>
        </aside>
    );
}
