import type {
    OpcUaBrowseAnswer,
    OpcUaBrowseResult,
    ServerMessage,
} from '../core/protocol.ts';

/**
 * Browses the project's OPC UA servers through the twin's gateway, which is
 * what connects to them. Opens its own socket on the first browse, so it
 * works whether the viewer follows the gateway or the simulator.
 */
export class OpcUaBrowser {
    private opened?: Promise<WebSocket>;
    private nextId = 1;
    private readonly pending = new Map<
        number,
        (answer: OpcUaBrowseAnswer | Error) => void
    >();

    constructor(private readonly url: string) {}

    private open(): Promise<WebSocket> {
        this.opened ??= new Promise((resolve, reject) => {
            const socket = new WebSocket(this.url);
            socket.onopen = () => resolve(socket);
            socket.onerror = () =>
                reject(new Error("Couldn't reach the twin's gateway"));
            socket.onclose = () => {
                this.opened = undefined;
                this.settle(new Error('The gateway closed the connection'));
            };
            socket.onmessage = (event) => {
                const message = JSON.parse(String(event.data)) as ServerMessage;
                if (message.type === 'opcua-browse') {
                    this.pending.get(message.id)?.(message);
                    this.pending.delete(message.id);
                } else if (message.type === 'error') {
                    this.settle(new Error(message.message));
                }
            };
        });
        const opened = this.opened;
        opened.catch(() => {
            if (this.opened === opened) this.opened = undefined;
        });
        return opened;
    }

    private settle(error: Error): void {
        for (const done of this.pending.values()) done(error);
        this.pending.clear();
    }

    /** The objects and variables one level below a node, or the server's Objects folder. */
    async browse(server: string, node?: string): Promise<OpcUaBrowseResult> {
        const socket = await this.open();
        const id = this.nextId++;
        const answer = await new Promise<OpcUaBrowseAnswer | Error>((done) => {
            this.pending.set(id, done);
            socket.send(
                JSON.stringify({ type: 'opcua-browse', id, server, node }),
            );
        });
        if (answer instanceof Error) throw answer;
        if ('error' in answer) throw new Error(answer.error);
        return answer;
    }

    close(): void {
        void this.opened?.then(
            (socket) => socket.close(),
            () => undefined,
        );
        this.opened = undefined;
    }
}
