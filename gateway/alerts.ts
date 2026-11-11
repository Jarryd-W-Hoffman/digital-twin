import { createRuleEngine, type RuleEngine } from '../resources/js/twin/core/rules.ts';
import type { Alert, Frame, Severity, TwinDefinition } from '../resources/js/twin/core/types.ts';
import type { ControlPlaneOptions } from './gateway.ts';

/** An open alert as the control plane is told of it: one per rule and asset. */
export interface ReportedAlert {
  rule: string;
  asset: string;
  severity: Severity;
  message: string;
  value: number;
}

/** How often the open alerts are sent even when nothing changed. */
export const HEARTBEAT_MS = 30_000;
/** How long to wait after the control plane refuses or can't be reached. */
export const RETRY_MS = 5_000;

/**
 * Watches a twin's rules on the gateway, so alerts are raised while nobody
 * has the twin open, and tells the control plane which are open
 * (POST /api/gateway/alerts). It sends the whole set, not events: as soon as
 * it changes, and every HEARTBEAT_MS anyway, so a restart, a lost request or
 * a new version can't leave the control plane out of step. An alert still
 * open after a restart is the same alert there, not a new one.
 */
export class AlertWatch {
  private engine: RuleEngine;
  private changed = true;
  private lastSent = Number.NEGATIVE_INFINITY;
  private retryAt = Number.NEGATIVE_INFINITY;
  private sending: Promise<void> | null = null;
  sent = 0;
  failed = 0;

  constructor(
    private readonly cp: ControlPlaneOptions | null,
    definition: TwinDefinition,
  ) {
    this.engine = createRuleEngine(definition, { remember: false });
  }

  /** Starts again on a new definition, whose rules may differ. */
  reset(definition: TwinDefinition): void {
    this.engine = createRuleEngine(definition, { remember: false });
    this.changed = true;
  }

  evaluate(frame: Frame): { raised: Alert[]; cleared: Alert[] } {
    const result = this.engine.evaluate(frame);
    if (result.raised.length > 0 || result.cleared.length > 0) this.changed = true;
    return result;
  }

  open(): ReportedAlert[] {
    return this.engine.open().map((a) => ({
      rule: a.ruleId,
      asset: a.assetId,
      severity: a.severity,
      message: a.message,
      value: Number.isFinite(a.value) ? a.value : 0,
    }));
  }

  /** Sends the open alerts if they changed or the heartbeat is due; one request at a time. */
  report(now = Date.now()): Promise<void> {
    if (!this.cp || this.sending) return this.sending ?? Promise.resolve();
    if (now < this.retryAt) return Promise.resolve();
    if (!this.changed && now - this.lastSent < HEARTBEAT_MS) return Promise.resolve();
    const cp = this.cp;
    // Taken now, so a change while this is in flight is sent next time.
    this.changed = false;
    const open = this.open();
    this.sending = fetch(`${cp.url.replace(/\/+$/, '')}/api/gateway/alerts`, {
      method: 'POST',
      headers: { authorization: `Bearer ${cp.token}`, accept: 'application/json', 'content-type': 'application/json' },
      body: JSON.stringify({ open }),
      signal: AbortSignal.timeout(10_000),
    })
      .then((res) => {
        if (!res.ok) throw new Error(`Control plane answered ${res.status} for the open alerts`);
        this.lastSent = now;
        this.sent++;
      })
      .catch((error: unknown) => {
        this.changed = true;
        this.failed++;
        this.retryAt = now + RETRY_MS;
        console.warn(`Gateway: ${error instanceof Error ? error.message : String(error)}`);
      })
      .finally(() => (this.sending = null));
    return this.sending;
  }
}
