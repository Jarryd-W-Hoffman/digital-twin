import type { Frame } from './types.ts';

/**
 * Fixed-capacity ring of frames in time order, for charts and time travel.
 * A frame arriving out of order is dropped: a reconnecting feed may replay
 * what it has already sent.
 */
export class History {
    private frames: Frame[] = [];
    private head = 0;
    private readonly capacity: number;

    constructor(capacity: number) {
        this.capacity = capacity;
    }

    get length(): number {
        return this.frames.length;
    }

    push(frame: Frame): boolean {
        const last = this.last();
        if (last && frame.t <= last.t) return false;
        if (this.frames.length < this.capacity) {
            this.frames.push(frame);
        } else {
            this.frames[this.head] = frame;
            this.head = (this.head + 1) % this.capacity;
        }
        return true;
    }

    at(index: number): Frame | undefined {
        if (index < 0 || index >= this.frames.length) return undefined;
        return this.frames[(this.head + index) % this.frames.length];
    }

    first(): Frame | undefined {
        return this.at(0);
    }

    last(): Frame | undefined {
        return this.at(this.frames.length - 1);
    }

    /** The newest frame at or before `t`, by binary search. */
    nearest(t: number): Frame | undefined {
        let lo = 0;
        let hi = this.frames.length - 1;
        let found: Frame | undefined;
        while (lo <= hi) {
            const mid = (lo + hi) >> 1;
            const f = this.at(mid)!;
            if (f.t <= t) {
                found = f;
                lo = mid + 1;
            } else {
                hi = mid - 1;
            }
        }
        return found ?? this.first();
    }

    /** Time and value pairs for one metric, thinned to at most `maxPoints`. */
    series(
        key: string,
        fromT = -Infinity,
        toT = Infinity,
        maxPoints = 240,
    ): { t: number; v: number }[] {
        return this.seriesOf((values) => values[key], fromT, toT, maxPoints);
    }

    /** Like `series`, for anything worked out from a frame's values. */
    seriesOf(
        valueOf: (values: Frame['values']) => number | undefined,
        fromT = -Infinity,
        toT = Infinity,
        maxPoints = 240,
    ): { t: number; v: number }[] {
        const inRange: { t: number; v: number }[] = [];
        for (let i = 0; i < this.frames.length; i++) {
            const f = this.at(i)!;
            if (f.t < fromT || f.t > toT) continue;
            const v = valueOf(f.values);
            if (v !== undefined) inRange.push({ t: f.t, v });
        }
        if (inRange.length <= maxPoints) return inRange;
        const stride = inRange.length / maxPoints;
        const out: { t: number; v: number }[] = [];
        for (let i = 0; i < maxPoints; i++)
            out.push(inRange[Math.floor(i * stride)]!);
        out[out.length - 1] = inRange[inRange.length - 1]!;
        return out;
    }
}
