import { State } from '../core/types.ts';
import type { Severity } from '../core/types.ts';
import { STATUS_COLOR, stateLabel, SEVERITY_COLOR } from '../scene/colors.ts';

const STATE_ICON: Record<number, string> = {
    [State.Off]: '◌',
    [State.Running]: '●',
    [State.Standby]: '○',
    [State.Maintenance]: '◆',
    [State.Fault]: '▲',
};

export function StateIcon({ state }: { state: number | undefined }) {
    const code = state ?? State.Off;
    return (
        <span
            className="state-icon"
            style={{ color: STATUS_COLOR[code] }}
            aria-hidden="true"
        >
            {STATE_ICON[code] ?? '·'}
        </span>
    );
}

export function StatusBadge({ state }: { state: number | undefined }) {
    return (
        <span className="badge">
            <StateIcon state={state} /> {stateLabel(state)}
        </span>
    );
}

const SEVERITY_LABEL: Record<Severity, string> = {
    info: 'Info',
    warning: 'Warning',
    critical: 'Critical',
};
const SEVERITY_ICON: Record<Severity, string> = {
    info: 'ℹ',
    warning: '▲',
    critical: '⬣',
};

export function SeverityBadge({ severity }: { severity: Severity }) {
    return (
        <span className="badge">
            <span
                style={{ color: SEVERITY_COLOR[severity] }}
                aria-hidden="true"
            >
                {SEVERITY_ICON[severity]}
            </span>{' '}
            {SEVERITY_LABEL[severity]}
        </span>
    );
}
