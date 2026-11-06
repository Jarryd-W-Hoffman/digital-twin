import type {
    Command,
    Frame,
    SimState,
    TwinDefinition,
} from '../core/types.ts';

export type SourceStatus = 'connecting' | 'online' | 'offline';

export interface SourceTick {
    history: Frame[];
    live: Frame;
    state: SimState;
    measured: string[];
    speed: number;
}

export interface SourceHandlers {
    onDefinition(def: TwinDefinition): void;
    onTick(tick: SourceTick): void;
    onStatus(status: SourceStatus, detail?: string): void;
}

/**
 * Where telemetry comes from. The UI and rules engine see only this interface,
 * so an in-browser simulator and a remote feed are interchangeable.
 */
export interface TwinSource {
    readonly kind: 'simulator' | 'gateway';
    readonly label: string;
    start(handlers: SourceHandlers): void;
    stop(): void;
    send(command: Command): void;
    setSpeed(speed: number): void;
}
