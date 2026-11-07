import type { Reading } from '../resources/js/twin/core/protocol.ts';
import type { ControlPlaneOptions } from './gateway.ts';

export interface LoggedReading {
  asset: string;
  metric: string;
  value: number;
  /** When it was measured, in Unix milliseconds. */
  at: number;
}

/** Most readings held while the control plane can't be reached; the oldest go first. */
export const MAX_BUFFERED = 10_000;
const BATCH = 1000;

/**
 * Sends readings to the control plane's history (POST /api/gateway/readings)
 * in batches. Readings the control plane didn't take are kept for the next
 * flush, up to MAX_BUFFERED.
 */
export class ReadingLog {
  private buffer: LoggedReading[] = [];
  private flushing: Promise<void> | null = null;
  dropped = 0;
  sent = 0;

  constructor(private readonly cp: ControlPlaneOptions) {}

  get pending(): number {
    return this.buffer.length;
  }

  add(readings: Reading[], at = Date.now()): void {
    for (const r of readings) this.buffer.push({ asset: r.asset, metric: r.metric, value: r.value, at });
    const over = this.buffer.length - MAX_BUFFERED;
    if (over > 0) {
      this.buffer.splice(0, over);
      this.dropped += over;
    }
  }

  /** Sends everything buffered, a batch at a time; stops at the first failure. */
  flush(): Promise<void> {
    this.flushing ??= this.send().finally(() => (this.flushing = null));
    return this.flushing;
  }

  private async send(): Promise<void> {
    while (this.buffer.length > 0) {
      const batch = this.buffer.slice(0, BATCH);
      const res = await fetch(`${this.cp.url.replace(/\/+$/, '')}/api/gateway/readings`, {
        method: 'POST',
        headers: { authorization: `Bearer ${this.cp.token}`, accept: 'application/json', 'content-type': 'application/json' },
        body: JSON.stringify({ readings: batch }),
        signal: AbortSignal.timeout(10_000),
      });
      if (!res.ok) throw new Error(`Control plane answered ${res.status} for ${batch.length} readings`);
      this.buffer.splice(0, batch.length);
      this.sent += batch.length;
    }
  }
}
