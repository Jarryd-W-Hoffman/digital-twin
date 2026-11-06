import { siteTime, SimRunner } from '../core/runner.ts';
import type { Command, TwinDefinition } from '../core/types.ts';
import type { SourceHandlers, TwinSource } from './types.ts';

const TICK_MS = 250;
const PREROLL_S = 8 * 3600;
const LIVE_HOUR = 14;

/** Runs the model in the browser. Needs no server. */
export class LocalSource implements TwinSource {
    readonly kind = 'simulator';
    readonly label = 'Simulator';
    private readonly definition: TwinDefinition;
    private runner?: SimRunner;
    private timer?: ReturnType<typeof setInterval>;
    private handlers?: SourceHandlers;

    constructor(definition: TwinDefinition) {
        this.definition = definition;
    }

    start(handlers: SourceHandlers): void {
        this.handlers = handlers;
        handlers.onDefinition(this.definition);
        const live = siteTime(
            Date.now(),
            this.definition.utcOffsetHours,
            LIVE_HOUR,
        );
        const runner = new SimRunner(this.definition, live - PREROLL_S * 1000);
        this.runner = runner;
        const history = runner.preroll(PREROLL_S);
        handlers.onStatus('online');
        this.emit(history);
        let last = performance.now();
        this.timer = setInterval(() => {
            const now = performance.now();
            this.emitTick((now - last) / 1000);
            last = now;
        }, TICK_MS);
    }

    private emitTick(seconds: number): void {
        const tick = this.runner!.tick(seconds);
        this.handlers?.onTick({ ...tick, speed: this.runner!.speed });
    }

    private emit(history: ReturnType<SimRunner['preroll']>): void {
        const tick = this.runner!.tick(0);
        this.handlers?.onTick({ ...tick, history, speed: this.runner!.speed });
    }

    stop(): void {
        clearInterval(this.timer);
    }

    send(command: Command): void {
        this.runner?.command(command);
        this.emitTick(0);
    }

    setSpeed(speed: number): void {
        if (this.runner) this.runner.speed = speed;
        this.emitTick(0);
    }
}
