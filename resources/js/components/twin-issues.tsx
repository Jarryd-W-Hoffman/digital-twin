import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import TwinIssueAssignmentController from '@/actions/App/Http/Controllers/TwinIssueAssignmentController';
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
    assignee: string | null;
    /** Whether it's given to whoever is looking. */
    mine: boolean;
    dueOn: string | null;
    overdue: boolean;
    comments: number;
    updatedAt: string;
};

/** An alert to raise an issue from, pinned at its asset. */
export type RaiseFrom = { id: string; asset: string; message: string };

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
    assignee: { id: string; name: string } | null;
    dueOn: string | null;
    overdue: boolean;
    /** The alert it was raised from. */
    alert: {
        message: string;
        asset: string;
        raisedAt: string;
        url: string;
    } | null;
    can: { assign: boolean; close: boolean; delete: boolean };
    /** Who it could be given to, when the viewer may assign it. */
    members: { id: string; name: string }[];
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
/** A due date (`2026-11-20`) as the reader writes dates, without shifting days. */
const day = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString();
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
    raise = null,
    onClose,
}: {
    twinId: string;
    issues: IssueSummary[];
    issue: IssueDetail | null;
    raise?: RaiseFrom | null;
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
                <IssueList twinId={twinId} issues={issues} raise={raise} />
            )}
        </aside>
    );
}

const SHOWING = {
    open: { label: 'Open', test: (i: IssueSummary) => !i.closed },
    mine: { label: 'Mine', test: (i: IssueSummary) => !i.closed && i.mine },
    closed: { label: 'Closed', test: (i: IssueSummary) => i.closed },
} as const;
const EMPTY = {
    open: 'Nothing open. Raise an issue to pin a problem or a note to a place in the scene.',
    mine: 'Nothing open is assigned to you.',
    closed: 'Nothing closed yet.',
} as const;

function IssueList({
    twinId,
    issues,
    raise,
}: {
    twinId: string;
    issues: IssueSummary[];
    raise: RaiseFrom | null;
}) {
    const [showing, setShowing] = useState<keyof typeof SHOWING>('open');
    const pinning = useTwin((s) => s.pinning);
    const placed = useTwin((s) => s.placedPin);
    const finding = useTwin((s) => s.assetPinRequest !== null);
    const shown = issues.filter(SHOWING[showing].test);

    return (
        <>
            {placed ? (
                <NewIssue twinId={twinId} placed={placed} raise={raise} />
            ) : pinning || (raise && finding) ? (
                <section>
                    <p className="editor-hint" role="status">
                        {pinning
                            ? "Click the spot in the scene where the issue is. The camera you're looking from is kept with it."
                            : `Finding ${raise?.asset} in the scene…`}
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
                    {(Object.keys(SHOWING) as (keyof typeof SHOWING)[]).map(
                        (option) => (
                            <button
                                key={option}
                                role="radio"
                                aria-checked={showing === option}
                                className={showing === option ? 'on' : ''}
                                onClick={() => setShowing(option)}
                            >
                                {SHOWING[option].label} (
                                {issues.filter(SHOWING[option].test).length})
                            </button>
                        ),
                    )}
                </div>
                {shown.length === 0 ? (
                    <p className="editor-hint">{EMPTY[showing]}</p>
                ) : (
                    <ul
                        className="issue-list"
                        aria-label={`${SHOWING[showing].label.toLowerCase()} issues`}
                    >
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
                                    {(i.assignee || i.dueOn) && (
                                        <span className="issue-meta">
                                            {i.assignee &&
                                                `For ${i.mine ? 'you' : i.assignee}`}
                                            {i.assignee && i.dueOn && ' · '}
                                            {i.dueOn && (
                                                <Due
                                                    on={i.dueOn}
                                                    overdue={i.overdue}
                                                />
                                            )}
                                        </span>
                                    )}
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
    raise,
}: {
    twinId: string;
    placed: { at: Vec3; view: CameraView };
    raise: RaiseFrom | null;
}) {
    const form = useForm<{ title: string; body: string; photo: File | null }>({
        title: raise ? raise.message.slice(0, 200) : '',
        body: '',
        photo: null,
    });

    return (
        <section aria-label="New issue">
            <h3>New issue</h3>
            {raise && (
                <p className="editor-hint">
                    Following up the alert on {raise.asset}. The pin is on it;
                    move it if the problem is elsewhere.
                </p>
            )}
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
                        ...(raise ? { alert: raise.id } : {}),
                    }));
                    form.submit(TwinIssueController.store(twinId), {
                        ...RELOAD,
                        only: [...RELOAD.only, 'raise'],
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
                {issue.alert && (
                    <p className="issue-meta">
                        From the alert{' '}
                        <a href={issue.alert.url}>“{issue.alert.message}”</a> on{' '}
                        {issue.alert.asset} · {when(issue.alert.raisedAt)}
                    </p>
                )}
                {issue.can.assign ? (
                    <Assignment
                        key={`${issue.assignee?.id}-${issue.dueOn}`}
                        twinId={twinId}
                        issue={issue}
                    />
                ) : (
                    (issue.assignee || issue.dueOn) && (
                        <p className="issue-meta">
                            {issue.assignee
                                ? `Assigned to ${issue.assignee.name}`
                                : 'Not assigned'}
                            {issue.dueOn && (
                                <>
                                    {' · '}
                                    <Due
                                        on={issue.dueOn}
                                        overdue={issue.overdue}
                                    />
                                </>
                            )}
                        </p>
                    )
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

/** When an issue is due, marked when it's late. */
function Due({ on, overdue }: { on: string; overdue: boolean }) {
    return overdue ? (
        <span className="issue-overdue">Overdue, due {day(on)}</span>
    ) : (
        <>Due {day(on)}</>
    );
}

/** Who an issue is given to and by when, for whoever may change them. */
function Assignment({ twinId, issue }: { twinId: string; issue: IssueDetail }) {
    const form = useForm({
        assignee: issue.assignee?.id ?? '',
        due_on: issue.dueOn ?? '',
    });
    const changed =
        form.data.assignee !== (issue.assignee?.id ?? '') ||
        form.data.due_on !== (issue.dueOn ?? '');

    return (
        <form
            className="issue-assignment"
            aria-label="Assignment"
            onSubmit={(e) => {
                e.preventDefault();
                form.submit(
                    TwinIssueAssignmentController.update([twinId, issue.id]),
                    RELOAD,
                );
            }}
        >
            <label className="editor-field">
                <span>Assigned to</span>
                <select
                    value={form.data.assignee}
                    onChange={(e) => form.setData('assignee', e.target.value)}
                >
                    <option value="">Nobody</option>
                    {issue.members.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="editor-field">
                <span>Due</span>
                <input
                    type="date"
                    value={form.data.due_on}
                    onChange={(e) => form.setData('due_on', e.target.value)}
                />
            </label>
            {issue.overdue && !changed && (
                <p className="issue-meta">
                    <span className="issue-overdue">Overdue</span>
                </p>
            )}
            {(form.errors.assignee || form.errors.due_on) && (
                <p className="editor-error" role="alert">
                    {form.errors.assignee ?? form.errors.due_on}
                </p>
            )}
            {changed && (
                <div className="editor-actions">
                    <button
                        type="submit"
                        className="primary"
                        disabled={form.processing}
                    >
                        Save assignment
                    </button>
                </div>
            )}
        </form>
    );
}
