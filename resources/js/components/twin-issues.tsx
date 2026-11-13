import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import TwinIssueClosureController from '@/actions/App/Http/Controllers/TwinIssueClosureController';
import TwinIssueCommentController from '@/actions/App/Http/Controllers/TwinIssueCommentController';
import TwinIssueController from '@/actions/App/Http/Controllers/TwinIssueController';
import { show } from '@/routes/twins';
import type { Vec3 } from '@/twin/core/measure';
import { useTwin, type CameraView } from '@/twin/state/store';

export type IssueSummary = {
    id: string;
    number: number;
    title: string;
    closed: boolean;
    at: Vec3;
    view: CameraView;
    author: string | null;
    comments: number;
    updatedAt: string;
};

export type IssueDetail = {
    id: string;
    number: number;
    title: string;
    at: Vec3;
    view: CameraView;
    author: string | null;
    createdAt: string;
    closedAt: string | null;
    closedBy: string | null;
    can: { close: boolean; delete: boolean };
    comments: {
        id: string;
        author: string | null;
        body: string | null;
        photo: string | null;
        createdAt: string;
        canDelete: boolean;
    }[];
};

/** Reloads only the issues after a change, so the running twin isn't restarted. */
const RELOAD = {
    only: ['issues', 'issue'],
    preserveScroll: true,
    preserveState: true,
};

const when = (iso: string) => new Date(iso).toLocaleString();
const who = (name: string | null) => name ?? 'a former member';

/**
 * Opens an issue in the panel, or goes back to the list given null. The
 * issue is in the address, so the link can be passed on.
 */
export function openIssue(twinId: string, id: string | null): void {
    router.get(
        show.url(twinId, { query: id ? { issue: id } : {} }),
        {},
        {
            only: ['issue'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

/**
 * The issues pinned in a twin's scene: the list, raising a new one by
 * clicking where it is, and one issue's conversation. Anyone who can see
 * the twin takes part.
 */
export function TwinIssues({
    twinId,
    issues,
    issue,
    onClose,
}: {
    twinId: string;
    issues: IssueSummary[];
    issue: IssueDetail | null;
    onClose: () => void;
}) {
    return (
        <aside className="panel editor" aria-label="Issues">
            <div className="editor-head">
                {issue ? (
                    <button
                        className="ghost"
                        onClick={() => openIssue(twinId, null)}
                    >
                        ← All issues
                    </button>
                ) : (
                    <h2>Issues</h2>
                )}
                <button
                    className="ghost"
                    onClick={onClose}
                    aria-label="Hide issues"
                >
                    ✕
                </button>
            </div>
            {issue ? (
                <OpenIssue key={issue.id} twinId={twinId} issue={issue} />
            ) : (
                <IssueList twinId={twinId} issues={issues} />
            )}
        </aside>
    );
}

function IssueList({
    twinId,
    issues,
}: {
    twinId: string;
    issues: IssueSummary[];
}) {
    const [showing, setShowing] = useState<'open' | 'closed'>('open');
    const pinning = useTwin((s) => s.pinning);
    const placed = useTwin((s) => s.placedPin);
    const counts = {
        open: issues.filter((i) => !i.closed).length,
        closed: issues.filter((i) => i.closed).length,
    };
    const shown = issues.filter((i) => i.closed === (showing === 'closed'));

    return (
        <>
            {placed ? (
                <NewIssue twinId={twinId} placed={placed} />
            ) : pinning ? (
                <section>
                    <p className="editor-hint" role="status">
                        Click the spot in the scene where the issue is. The
                        camera you're looking from is kept with it.
                    </p>
                    <div className="editor-actions">
                        <button
                            onClick={() => useTwin.getState().clearPlacedPin()}
                        >
                            Cancel
                        </button>
                    </div>
                </section>
            ) : (
                <button
                    className="primary"
                    onClick={() => useTwin.getState().setPinning(true)}
                >
                    New issue
                </button>
            )}

            <section>
                <div className="segmented" role="radiogroup" aria-label="Show">
                    {(['open', 'closed'] as const).map((option) => (
                        <button
                            key={option}
                            role="radio"
                            aria-checked={showing === option}
                            className={showing === option ? 'on' : ''}
                            onClick={() => setShowing(option)}
                        >
                            {option === 'open' ? 'Open' : 'Closed'} (
                            {counts[option]})
                        </button>
                    ))}
                </div>
                {shown.length === 0 ? (
                    <p className="editor-hint">
                        {showing === 'open'
                            ? 'Nothing open. Raise an issue to pin a problem or a note to a place in the scene.'
                            : 'Nothing closed yet.'}
                    </p>
                ) : (
                    <ul className="issue-list" aria-label={`${showing} issues`}>
                        {shown.map((i) => (
                            <li key={i.id}>
                                <button
                                    className="issue-row"
                                    onClick={() => {
                                        useTwin.getState().flyTo(i.view);
                                        openIssue(twinId, i.id);
                                    }}
                                >
                                    <span className="issue-row-title">
                                        <span className="issue-number">
                                            #{i.number}
                                        </span>{' '}
                                        {i.title}
                                    </span>
                                    <span className="issue-meta">
                                        {who(i.author)} ·{' '}
                                        {i.comments === 1
                                            ? '1 comment'
                                            : `${i.comments} comments`}{' '}
                                        · {when(i.updatedAt)}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

function NewIssue({
    twinId,
    placed,
}: {
    twinId: string;
    placed: { at: Vec3; view: CameraView };
}) {
    const form = useForm<{ title: string; body: string; photo: File | null }>({
        title: '',
        body: '',
        photo: null,
    });

    return (
        <section aria-label="New issue">
            <h3>New issue</h3>
            <form
                className="editor-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        at: {
                            lon: placed.at[0],
                            lat: placed.at[1],
                            height: placed.at[2],
                        },
                        view: placed.view,
                    }));
                    form.submit(TwinIssueController.store(twinId), {
                        ...RELOAD,
                        onSuccess: () => useTwin.getState().clearPlacedPin(),
                    });
                }}
            >
                <label className="editor-field">
                    <span>Title</span>
                    <input
                        value={form.data.title}
                        maxLength={200}
                        required
                        autoFocus
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                {form.errors.title && (
                    <p className="editor-error" role="alert">
                        {form.errors.title}
                    </p>
                )}
                <label className="editor-field">
                    <span>Note</span>
                    <textarea
                        value={form.data.body}
                        maxLength={5000}
                        rows={3}
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                </label>
                <label className="editor-field">
                    <span>Photo</span>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={(e) =>
                            form.setData('photo', e.target.files?.[0] ?? null)
                        }
                    />
                </label>
                {(form.errors.photo || form.errors.body) && (
                    <p className="editor-error" role="alert">
                        {form.errors.photo ?? form.errors.body}
                    </p>
                )}
                <div className="editor-actions">
                    <button
                        type="submit"
                        className="primary"
                        disabled={form.processing}
                    >
                        Raise issue
                    </button>
                    <button
                        type="button"
                        onClick={() => useTwin.getState().setPinning(true)}
                    >
                        Move pin
                    </button>
                    <button
                        type="button"
                        className="ghost"
                        onClick={() => useTwin.getState().clearPlacedPin()}
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    );
}

function OpenIssue({ twinId, issue }: { twinId: string; issue: IssueDetail }) {
    const ids: [string, string] = [twinId, issue.id];

    return (
        <>
            <section>
                <h2 className="issue-title">
                    <span className="issue-number">#{issue.number}</span>{' '}
                    {issue.title}
                </h2>
                <p className="issue-meta">
                    Raised by {who(issue.author)} · {when(issue.createdAt)}
                </p>
                {issue.closedAt && (
                    <p className="issue-meta">
                        <span className="issue-closed">Closed</span> by{' '}
                        {who(issue.closedBy)} · {when(issue.closedAt)}
                    </p>
                )}
                <div className="editor-actions">
                    <button
                        onClick={() => useTwin.getState().flyTo(issue.view)}
                    >
                        Go to view
                    </button>
                    {issue.can.close &&
                        (issue.closedAt ? (
                            <button
                                onClick={() =>
                                    router.delete(
                                        TwinIssueClosureController.destroy.url(
                                            ids,
                                        ),
                                        RELOAD,
                                    )
                                }
                            >
                                Reopen
                            </button>
                        ) : (
                            <button
                                onClick={() =>
                                    router.post(
                                        TwinIssueClosureController.store.url(
                                            ids,
                                        ),
                                        {},
                                        RELOAD,
                                    )
                                }
                            >
                                Close issue
                            </button>
                        ))}
                    {issue.can.delete && (
                        <button
                            className="ghost"
                            onClick={() => {
                                if (
                                    confirm(
                                        `Delete issue #${issue.number} and its comments and photos?`,
                                    )
                                )
                                    router.delete(
                                        TwinIssueController.destroy.url(ids),
                                        RELOAD,
                                    );
                            }}
                        >
                            Delete
                        </button>
                    )}
                </div>
            </section>

            <section>
                <h3>Comments</h3>
                {issue.comments.length === 0 ? (
                    <p className="editor-hint">No comments yet.</p>
                ) : (
                    <ol className="issue-comments" aria-label="Comments">
                        {issue.comments.map((c) => (
                            <li key={c.id}>
                                <div className="issue-comment-head">
                                    <span className="issue-meta">
                                        {who(c.author)} · {when(c.createdAt)}
                                    </span>
                                    {c.canDelete && (
                                        <button
                                            className="ghost"
                                            aria-label="Delete comment"
                                            title="Delete comment"
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        'Delete this comment?',
                                                    )
                                                )
                                                    router.delete(
                                                        TwinIssueCommentController.destroy.url(
                                                            [...ids, c.id],
                                                        ),
                                                        RELOAD,
                                                    );
                                            }}
                                        >
                                            ✕
                                        </button>
                                    )}
                                </div>
                                {c.body && (
                                    <p className="issue-body">{c.body}</p>
                                )}
                                {c.photo && (
                                    <a
                                        href={c.photo}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="issue-photo"
                                    >
                                        <img
                                            src={c.photo}
                                            alt={`Photo from ${who(c.author)}`}
                                            loading="lazy"
                                        />
                                    </a>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            <CommentForm twinId={twinId} issueId={issue.id} />
        </>
    );
}

function CommentForm({ twinId, issueId }: { twinId: string; issueId: string }) {
    const form = useForm<{ body: string; photo: File | null }>({
        body: '',
        photo: null,
    });
    // A new file input after each comment, since a chosen file can't be cleared.
    const [sent, setSent] = useState(0);

    return (
        <form
            className="editor-form"
            aria-label="Add a comment"
            onSubmit={(e) => {
                e.preventDefault();
                form.submit(
                    TwinIssueCommentController.store([twinId, issueId]),
                    {
                        ...RELOAD,
                        onSuccess: () => {
                            form.reset();
                            setSent(sent + 1);
                        },
                    },
                );
            }}
        >
            <label className="editor-field">
                <span>Comment</span>
                <textarea
                    value={form.data.body}
                    maxLength={5000}
                    rows={3}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
            </label>
            <label className="editor-field">
                <span>Photo</span>
                <input
                    key={sent}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    onChange={(e) =>
                        form.setData('photo', e.target.files?.[0] ?? null)
                    }
                />
            </label>
            {(form.errors.body || form.errors.photo) && (
                <p className="editor-error" role="alert">
                    {form.errors.body ?? form.errors.photo}
                </p>
            )}
            <div className="editor-actions">
                <button
                    type="submit"
                    className="primary"
                    disabled={form.processing}
                >
                    Comment
                </button>
            </div>
        </form>
    );
}
