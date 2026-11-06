import type { Command, Frame, SimState, TwinDefinition } from './types.ts';

/** Messages on the gateway WebSocket. Everything is JSON. */
export type ServerMessage =
    | {
          type: 'hello';
          definition: TwinDefinition;
          speed: number;
          history: Frame[];
          live: Frame;
          state: SimState;
          measured: string[];
      }
    | {
          type: 'tick';
          history: Frame[];
          live: Frame;
          state: SimState;
          measured: string[];
          speed: number;
      }
    | { type: 'error'; message: string };

export type ClientMessage =
    | { type: 'command'; command: Command }
    | { type: 'speed'; speed: number };

export const SPEEDS = [0, 1, 60, 600] as const;

/** A reading sent to `POST /ingest`, either alone or in an array. */
export interface Reading {
    asset: string;
    metric: string;
    value: number;
    /** How long the reading holds, in simulated seconds. Defaults to 300. */
    ttlS?: number;
}
