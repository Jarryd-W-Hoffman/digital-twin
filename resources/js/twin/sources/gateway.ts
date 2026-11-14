import type { ClientMessage, ServerMessage } from '../core/protocol.ts';
import type { Command } from '../core/types.ts';
import { parseDefinition } from '../core/validate.ts';
import type { SourceHandlers, TwinSource } from './types.ts';

const MAX_RETRY_MS = 15_000;

/** Follows a remote gateway over WebSocket, reconnecting with backoff. */
export class GatewaySource implements TwinSource {
    readonly kind = 'gateway';
    readonly label: string;
    private socket?: WebSocket;
    private handlers?: SourceHandlers;
    private retryMs = 500;
    private retryTimer?: ReturnType<typeof setTimeout>;
    private stopped = false;
    private url: string;

    /**
     * @param refresh asked for a new URL (with a new ticket) before each
     *   reconnect; null means the twin can no longer be followed.
     */
    constructor(
        url: string,
        private readonly refresh?: () => Promise<string | null>,
    ) {
        this.url = url;
        this.label = `Gateway ${new URL(url).host}`;
    }

    start(handlers: SourceHandlers): void {
        this.handlers = handlers;
        this.connect();
    }

    private connect(): void {
        this.handlers?.onStatus('connecting');
        const socket = new WebSocket(this.url);
        this.socket = socket;
        socket.onmessage = (event) =>
            this.receive(JSON.parse(String(event.data)) as ServerMessage);
        socket.onclose = () => {
            if (this.stopped) return;
            this.handlers?.onStatus('offline', `Reconnecting to ${this.url}`);
            this.retryTimer = setTimeout(
                () => void this.reconnect(),
                this.retryMs,
            );
            this.retryMs = Math.min(MAX_RETRY_MS, this.retryMs * 2);
        };
    }

    private async reconnect(): Promise<void> {
        if (this.refresh) {
            const url = await this.refresh().catch(() => this.url);
            if (this.stopped) return;
            if (url === null) {
                this.stopped = true;
                this.handlers?.onStatus(
                    'offline',
                    'This twin can no longer be followed live',
                );
                return;
            }
            this.url = url;
        }
        this.connect();
    }

    private receive(message: ServerMessage): void {
        switch (message.type) {
            case 'hello':
                this.retryMs = 500;
                this.handlers?.onDefinition(
                    parseDefinition(message.definition),
                );
                this.handlers?.onStatus('online');
                this.handlers?.onTick({ ...message });
                break;
            case 'tick':
                this.handlers?.onTick(message);
                break;
            case 'error':
                console.error(`Gateway: ${message.message}`);
                break;
        }
    }

    private post(message: ClientMessage): void {
        if (this.socket?.readyState === WebSocket.OPEN)
            this.socket.send(JSON.stringify(message));
    }

    stop(): void {
        this.stopped = true;
        clearTimeout(this.retryTimer);
        this.socket?.close();
    }

    send(command: Command): void {
        this.post({ type: 'command', command });
    }

    setSpeed(speed: number): void {
        this.post({ type: 'speed', speed });
    }
}
