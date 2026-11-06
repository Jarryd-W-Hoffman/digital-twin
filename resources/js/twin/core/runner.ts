import {
    advance,
    applyCommand,
    compile,
    initialState,
    STEP_S,
    type Model,
} from './engine.ts';
import type { Command, Frame, SimState, TwinDefinition } from './types.ts';

/**
 * Runs the model against real elapsed time, in the browser or in the gateway.
 * Produces a history frame per simulated minute, plus a live frame each tick.
 * Measured values override the model: the next step integrates from the
 * reading, so the twin follows reality wherever a sensor reports.
 */

export const HISTORY_FRAME_S = 60;

export interface Tick {
    history: Frame[];
    live: Frame;
    state: SimState;
    measured: string[];
}

interface Override {
    value: number;
    expiresT: number;
}

export class SimRunner {
    readonly model: Model;
    state: SimState;
    speed: number;
    private remainder = 0;
    private overrides = new Map<string, Override>();

    constructor(def: TwinDefinition, startT: number, speed = 60) {
        this.model = compile(def);
        this.state = initialState(this.model, startT);
        this.speed = speed;
    }

    /** Runs ahead by `seconds` as fast as possible, keeping history frames. */
    preroll(seconds: number): Frame[] {
        const frames: Frame[] = [];
        this.run(seconds, frames);
        return frames;
    }

    tick(realSeconds: number): Tick {
        const history: Frame[] = [];
        this.remainder += realSeconds * this.speed;
        const whole = Math.floor(this.remainder / STEP_S) * STEP_S;
        if (whole > 0) {
            this.remainder -= whole;
            this.run(whole, history);
        }
        return {
            history,
            live: { t: this.state.t, values: { ...this.state.values } },
            state: this.state,
            measured: [...this.overrides.keys()],
        };
    }

    command(command: Command): void {
        applyCommand(this.model, this.state, command);
    }

    /** Pins a metric to a reading for `ttlS` simulated seconds. */
    measure(key: string, value: number, ttlS = 300): void {
        this.overrides.set(key, {
            value,
            expiresT: this.state.t + ttlS * 1000,
        });
        this.state.values[key] = value;
    }

    private run(seconds: number, history: Frame[]): void {
        const steps = Math.max(1, Math.round(seconds / STEP_S));
        for (let i = 0; i < steps; i++) {
            const before = Math.floor(this.state.t / (HISTORY_FRAME_S * 1000));
            advance(this.model, this.state, STEP_S);
            for (const [key, o] of this.overrides) {
                if (this.state.t >= o.expiresT) this.overrides.delete(key);
                else this.state.values[key] = o.value;
            }
            if (
                Math.floor(this.state.t / (HISTORY_FRAME_S * 1000)) !== before
            ) {
                history.push({
                    t: this.state.t,
                    values: { ...this.state.values },
                });
            }
        }
    }
}

/** Start of the current day at the site, in epoch ms, plus `hour`. */
export function siteTime(
    now: number,
    utcOffsetHours: number,
    hour: number,
): number {
    const shifted = now + utcOffsetHours * 3_600_000;
    const dayStart = shifted - (shifted % 86_400_000);
    return dayStart - utcOffsetHours * 3_600_000 + hour * 3_600_000;
}
