import { afterEach, describe, expect, it, vi } from 'vitest';
import { WebSocketServer } from 'ws';
import { GatewaySource } from './gateway.ts';
import type { SourceHandlers } from './types.ts';

const servers: WebSocketServer[] = [];

/** A gateway that greets each browser and then drops it, noting the URL it came with. */
async function droppingGateway() {
    const urls: string[] = [];
    const wss = new WebSocketServer({ port: 0, host: '127.0.0.1' });
    servers.push(wss);
    await new Promise<void>((done) => wss.on('listening', done));
    wss.on('connection', (socket, req) => {
        urls.push(req.url ?? '');
        socket.close(4001, 'Ticket expired');
    });
    const { port } = wss.address() as { port: number };
    return { urls, base: `ws://127.0.0.1:${port}/ws` };
}

const handlers = (): SourceHandlers & { statuses: [string, string?][] } => {
    const statuses: [string, string?][] = [];
    return {
        statuses,
        onStatus: (status: string, detail?: string) =>
            statuses.push([status, detail]),
        onDefinition: vi.fn(),
        onTick: vi.fn(),
    } as unknown as SourceHandlers & { statuses: [string, string?][] };
};

afterEach(() => {
    for (const wss of servers.splice(0)) wss.close();
});

describe('following a gateway', () => {
    it('asks for a new URL before reconnecting, when it can', async () => {
        const gw = await droppingGateway();
        let n = 1;
        const refresh = vi.fn(async () => `${gw.base}?ticket=${++n}`);
        const source = new GatewaySource(`${gw.base}?ticket=1`, refresh);
        source.start(handlers());

        await vi.waitFor(
            () => expect(gw.urls).toEqual(['/ws?ticket=1', '/ws?ticket=2']),
            { timeout: 3000 },
        );
        source.stop();
        expect(refresh).toHaveBeenCalled();
    });

    it('stops when there is no longer a URL to follow', async () => {
        const gw = await droppingGateway();
        const h = handlers();
        const source = new GatewaySource(
            `${gw.base}?ticket=1`,
            async () => null,
        );
        source.start(h);

        await vi.waitFor(
            () =>
                expect(h.statuses.at(-1)).toEqual([
                    'offline',
                    'This twin can no longer be followed live',
                ]),
            { timeout: 3000 },
        );
        await new Promise((done) => setTimeout(done, 700));
        expect(gw.urls).toEqual(['/ws?ticket=1']);
    });

    it('keeps the URL it has when asking fails', async () => {
        const gw = await droppingGateway();
        const source = new GatewaySource(`${gw.base}?ticket=1`, () =>
            Promise.reject(new Error('offline')),
        );
        source.start(handlers());

        await vi.waitFor(
            () =>
                expect(gw.urls.slice(0, 2)).toEqual([
                    '/ws?ticket=1',
                    '/ws?ticket=1',
                ]),
            { timeout: 3000 },
        );
        source.stop();
    });
});
