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
    | { type: 'error'; message: string }
    | OpcUaBrowseAnswer;

export type ClientMessage =
    | { type: 'command'; command: Command }
    | { type: 'speed'; speed: number }
    /** One level of a project server's nodes, for an editor choosing what to map. */
    | { type: 'opcua-browse'; id: number; server: string; node?: string };

/** One node found by browsing an OPC UA server. */
export interface OpcUaBrowsedNode {
    node: string;
    name: string;
    kind: 'object' | 'variable';
    /** A variable's built-in type, such as Double, or its type's node id. */
    dataType?: string;
    /** A variable's value now, when it's a single number, boolean or text. */
    value?: number | boolean | string | null;
    /** Whether the gateway's login may write it. */
    writable?: boolean;
}

export interface OpcUaBrowseResult {
    node: string;
    children: OpcUaBrowsedNode[];
    /** The server had more children than were listed. */
    more: boolean;
}

/** The answer to an `opcua-browse`, with its id: the nodes, or why not. */
export type OpcUaBrowseAnswer = {
    type: 'opcua-browse';
    id: number;
    server: string;
} & (OpcUaBrowseResult | { node: string; error: string });

export const SPEEDS = [0, 1, 60, 600] as const;

/** A reading sent to `POST /ingest`, either alone or in an array. */
export interface Reading {
    asset: string;
    metric: string;
    value: number;
    /** How long the reading holds, in simulated seconds. Defaults to 300. */
    ttlS?: number;
}
