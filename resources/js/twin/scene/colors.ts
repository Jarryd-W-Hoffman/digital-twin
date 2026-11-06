import { STATE_LABEL, State } from '../core/types.ts';

/**
 * Scene and UI share one status vocabulary. Status colours are reserved for
 * state and never carry data, and the UI always pairs them with an icon and label.
 */
export const STATUS_COLOR: Record<number, string> = {
    [State.Off]: '#4b5563',
    [State.Running]: '#3fb68b',
    [State.Standby]: '#8a9bb0',
    [State.Maintenance]: '#5aa2e6',
    [State.Fault]: '#ef5350',
};

/**
 * Bodies in the scene. Healthy is deliberately quiet, so that a fault is the
 * only loud thing on screen.
 */
export const BODY_COLOR: Record<number, string> = {
    ...STATUS_COLOR,
    [State.Running]: '#5d8579',
    [State.Standby]: '#77838f',
    [State.Off]: '#333b45',
};

export const SEVERITY_COLOR = {
    info: '#5aa2e6',
    warning: '#f2b84b',
    critical: '#ef5350',
} as const;

export const stateLabel = (state: number | undefined) =>
    state === undefined ? 'No data' : (STATE_LABEL[state] ?? 'Unknown');

type Rgb = [number, number, number];

const hexToRgb = (hex: string): Rgb => [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
];

const mix = (a: Rgb, b: Rgb, t: number): string => {
    const c = a.map((v, i) => Math.round(v + (b[i]! - v) * t));
    return `#${c.map((v) => v.toString(16).padStart(2, '0')).join('')}`;
};

const COLD = hexToRgb('#2f7fd8');
const NEUTRAL = hexToRgb('#8d97a3');
const HOT = hexToRgb('#e4572e');
const POWER_LOW = hexToRgb('#3a424d');
const POWER_HIGH = hexToRgb('#ffc95c');

/** Diverging: blue below setpoint, grey at it, red toward the limit. */
export function temperatureColor(
    temp: number,
    setpoint: number,
    limit: number,
): string {
    const span = Math.max(0.5, limit - setpoint);
    const d = (temp - setpoint) / span;
    return d >= 0
        ? mix(NEUTRAL, HOT, Math.min(1, d))
        : mix(NEUTRAL, COLD, Math.min(1, -d));
}

/** Sequential in one hue: dim at zero load, bright at full. */
export function powerColor(fraction: number): string {
    return mix(POWER_LOW, POWER_HIGH, Math.min(1, Math.max(0, fraction)));
}

export const INERT_COLOR = '#39424e';
