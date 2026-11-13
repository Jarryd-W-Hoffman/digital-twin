import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import TwinOpeningViewController from '@/actions/App/Http/Controllers/TwinOpeningViewController';
import TwinViewController from '@/actions/App/Http/Controllers/TwinViewController';
import { show } from '@/routes/twins';
import { encodeView, type ViewState } from '@/twin/core/views';
import { applyView, currentView } from '@/twin/state/store';

export type SavedViewSummary = {
    id: string;
    name: string;
    author: string | null;
    mine: boolean;
    shared: boolean;
    opening: boolean;
    state: ViewState;
    can: { update: boolean; delete: boolean; share: boolean };
};

/** Reloads only the views after a change, so the running twin isn't restarted. */
const RELOAD = {
    only: ['views'],
    preserveScroll: true,
    preserveState: true,
};

const who = (name: string | null) => name ?? 'a former member';

/** A link that opens the twin at a saved view, or as it looks now. */
function linkTo(twinId: string, query: { view: string } | { look: string }) {
    return new URL(show.url(twinId, { query }), window.location.origin).href;
}

/**
 * Views of a twin people keep to come back to: saving what is on screen,
 * opening a view, and links that open the twin as it looked. Views are
 * private until an editor shares them; one shared view can be where the
 * twin opens.
 */
export function TwinViews({
    twinId,
    views,
    canEdit,
    onClose,
}: {
    twinId: string;
    views: SavedViewSummary[];
    canEdit: boolean;
    onClose: () => void;
}) {
    const [link, setLink] = useState<string | null>(null);
    const share = (url: string) => {
        setLink(url);
        void navigator.clipboard?.writeText(url).catch(() => undefined);
    };
    const shared = views.filter((v) => v.shared);
    const yours = views.filter((v) => !v.shared);

    return (
        <aside className="panel editor" aria-label="Views">
            <div className="editor-head">
                <h2>Views</h2>
                <button
                    className="ghost"
                    onClick={onClose}
                    aria-label="Hide views"
                >
                    ✕
                </button>
            </div>

            <SaveView twinId={twinId} />
            <section>
                <button
                    onClick={() => {
                        const view = currentView();
                        if (view)
                            share(linkTo(twinId, { look: encodeView(view) }));
                    }}
                >
                    Copy link to what you see
                </button>
                {link && (
                    <label className="editor-field">
                        <span>Link, copied</span>
                        <input
                            readOnly
                            value={link}
                            onFocus={(e) => e.target.select()}
                        />
                    </label>
                )}
                <p className="editor-hint">
                    A link opens the twin with this camera, colouring, layers,
                    section and selection, for anyone in the organisation.
                </p>
            </section>

            <ViewGroup
                title="Shared"
                label="Shared views"
                empty={
                    canEdit
                        ? 'Nothing shared yet. Share one of your views so everyone sees it here.'
                        : 'Nothing shared yet.'
                }
                twinId={twinId}
                views={shared}
                canEdit={canEdit}
                onLink={share}
            />
            <ViewGroup
                title="Yours"
                label="Your views"
                empty="Views you save show here, just for you, until they're shared."
                twinId={twinId}
                views={yours}
                canEdit={canEdit}
                onLink={share}
            />
        </aside>
    );
}

function SaveView({ twinId }: { twinId: string }) {
    const form = useForm({ name: '' });
    const [failed, setFailed] = useState(false);

    return (
        <section aria-label="Save view">
            <form
                className="editor-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    const state = currentView();
                    setFailed(!state);
                    if (!state) return;
                    form.transform((data) => ({ ...data, state }));
                    form.submit(TwinViewController.store(twinId), {
                        ...RELOAD,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <label className="editor-field">
                    <span>Name</span>
                    <input
                        value={form.data.name}
                        maxLength={100}
                        required
                        placeholder="Chiller at night"
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                </label>
                {(form.errors.name || failed) && (
                    <p className="editor-error" role="alert">
                        {form.errors.name ?? 'The scene is still loading.'}
                    </p>
                )}
                <div className="editor-actions">
                    <button
                        type="submit"
                        className="primary"
                        disabled={form.processing}
                    >
                        Save view
                    </button>
                </div>
            </form>
        </section>
    );
}

function ViewGroup({
    title,
    label,
    empty,
    twinId,
    views,
    canEdit,
    onLink,
}: {
    title: string;
    label: string;
    empty: string;
    twinId: string;
    views: SavedViewSummary[];
    canEdit: boolean;
    onLink: (url: string) => void;
}) {
    return (
        <section aria-label={label}>
            <h3>{title}</h3>
            {views.length === 0 ? (
                <p className="editor-hint">{empty}</p>
            ) : (
                <ul className="view-list">
                    {views.map((view) => (
                        <ViewRow
                            key={view.id}
                            twinId={twinId}
                            view={view}
                            canEdit={canEdit}
                            onLink={onLink}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function ViewRow({
    twinId,
    view,
    canEdit,
    onLink,
}: {
    twinId: string;
    view: SavedViewSummary;
    canEdit: boolean;
    onLink: (url: string) => void;
}) {
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(view.name);
    const ids: [string, string] = [twinId, view.id];
    const update = (
        data: { name: string } | { shared: boolean } | { state: ViewState },
    ) => router.patch(TwinViewController.update.url(ids), data, RELOAD);

    return (
        <li className="view-row" aria-label={view.name}>
            {renaming ? (
                <form
                    className="view-rename"
                    onSubmit={(e) => {
                        e.preventDefault();
                        update({ name });
                        setRenaming(false);
                    }}
                >
                    <input
                        aria-label="Name"
                        value={name}
                        maxLength={100}
                        required
                        autoFocus
                        onChange={(e) => setName(e.target.value)}
                    />
                    <button type="submit">Save</button>
                    <button
                        type="button"
                        className="ghost"
                        onClick={() => {
                            setName(view.name);
                            setRenaming(false);
                        }}
                    >
                        Cancel
                    </button>
                </form>
            ) : (
                <button
                    className="view-open"
                    onClick={() => applyView(view.state)}
                >
                    <span className="view-name">{view.name}</span>
                    <span className="issue-meta">
                        {view.mine ? 'You' : who(view.author)}
                        {view.opening && (
                            <>
                                {' · '}
                                <span className="view-badge">
                                    Opens the twin
                                </span>
                            </>
                        )}
                    </span>
                </button>
            )}
            <div className="view-actions">
                <button
                    className="ghost"
                    onClick={() => onLink(linkTo(twinId, { view: view.id }))}
                >
                    Copy link
                </button>
                {view.can.update && (
                    <>
                        <button
                            className="ghost"
                            onClick={() => setRenaming(true)}
                        >
                            Rename
                        </button>
                        <button
                            className="ghost"
                            title="Save what is on screen over this view"
                            onClick={() => {
                                const state = currentView();
                                if (state) update({ state });
                            }}
                        >
                            Update
                        </button>
                    </>
                )}
                {view.can.share && (
                    <button
                        className="ghost"
                        onClick={() => update({ shared: !view.shared })}
                    >
                        {view.shared ? 'Stop sharing' : 'Share'}
                    </button>
                )}
                {canEdit &&
                    (view.opening ? (
                        <button
                            className="ghost"
                            onClick={() =>
                                router.delete(
                                    TwinOpeningViewController.destroy.url(
                                        twinId,
                                    ),
                                    RELOAD,
                                )
                            }
                        >
                            Open at whole site
                        </button>
                    ) : (
                        <button
                            className="ghost"
                            title="Open the twin at this view, for everyone"
                            onClick={() =>
                                router.post(
                                    TwinOpeningViewController.store.url(ids),
                                    {},
                                    RELOAD,
                                )
                            }
                        >
                            Open twin here
                        </button>
                    ))}
                {view.can.delete && (
                    <button
                        className="ghost danger"
                        onClick={() => {
                            if (confirm(`Delete the view "${view.name}"?`))
                                router.delete(
                                    TwinViewController.destroy.url(ids),
                                    RELOAD,
                                );
                        }}
                    >
                        Delete
                    </button>
                )}
            </div>
        </li>
    );
}
