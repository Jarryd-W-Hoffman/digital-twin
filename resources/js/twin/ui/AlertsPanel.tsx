import { useState } from 'react';
import { activeAt } from '../core/rules.ts';
import { alertKey, useTwin } from '../state/store.ts';
import { clock } from './format.ts';
import { SeverityBadge } from './StatusBadge.tsx';

export function AlertsPanel() {
    const def = useTwin((s) => s.def);
    const alerts = useTwin((s) => s.alerts);
    const view = useTwin((s) => s.view);
    const select = useTwin((s) => s.select);
    const acknowledge = useTwin((s) => s.acknowledge);
    const scrubTo = useTwin((s) => s.scrubTo);
    const server = useTwin((s) => s.serverAlerts);
    const [showAll, setShowAll] = useState(false);
    if (!def) return null;
    const t = view?.t ?? 0;
    const list = alerts
        .filter((a) => a.raisedAt <= t && (showAll || activeAt(a, t)))
        .slice()
        .sort((a, b) => b.raisedAt - a.raisedAt);

    return (
        <div className="inspector">
            <div className="inspector-head row-between">
                <h2>Alerts</h2>
                {server && (
                    <a className="link" href={server.logUrl}>
                        Alert log
                    </a>
                )}
                <label className="check">
                    <input
                        type="checkbox"
                        checked={showAll}
                        onChange={() => setShowAll(!showAll)}
                    />
                    Include cleared
                </label>
            </div>
            {list.length === 0 && (
                <p className="muted pad">
                    {showAll
                        ? 'Nothing has been raised yet.'
                        : 'Nothing is active right now.'}{' '}
                    Try a scenario under What-if.
                </p>
            )}
            <ul className="alert-list">
                {list.map((a) => {
                    const isActive = activeAt(a, t);
                    const shared = isActive
                        ? server?.open[alertKey(a.ruleId, a.assetId)]
                        : undefined;
                    const ackedBy = shared?.acknowledgedBy ?? null;
                    return (
                        <li
                            key={a.id}
                            className={`alert-item ${isActive ? '' : 'is-cleared'}`}
                        >
                            <div className="alert-top">
                                <SeverityBadge severity={a.severity} />
                                <span className="muted mono">
                                    {clock(a.raisedAt, def.utcOffsetHours)}
                                </span>
                            </div>
                            <p>{a.message}</p>
                            <div className="alert-actions">
                                <button
                                    className="link"
                                    onClick={() =>
                                        select(
                                            a.assetId === 'site'
                                                ? null
                                                : a.assetId,
                                            true,
                                        )
                                    }
                                >
                                    Show
                                </button>
                                <button
                                    className="link"
                                    onClick={() => scrubTo(a.raisedAt)}
                                >
                                    Replay from here
                                </button>
                                {ackedBy !== null ? (
                                    <span className="muted">
                                        Acknowledged by {ackedBy}
                                    </span>
                                ) : a.ackedAt !== undefined ? (
                                    <span className="muted">Acknowledged</span>
                                ) : (
                                    <button
                                        className="link"
                                        onClick={() => acknowledge(a.id)}
                                    >
                                        Acknowledge
                                    </button>
                                )}
                                {!isActive && a.clearedAt !== undefined && (
                                    <span className="muted">
                                        Cleared{' '}
                                        {clock(a.clearedAt, def.utcOffsetHours)}
                                    </span>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
