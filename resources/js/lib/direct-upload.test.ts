import { describe, expect, it, vi } from 'vitest';
import { DirectUploadError, directUpload } from './direct-upload.ts';

const routes = {
    start: '/projects/p/uploads',
    parts: (id: string) => `/projects/p/uploads/${id}/parts`,
    complete: (id: string) => `/projects/p/uploads/${id}/complete`,
    abort: (id: string) => `/projects/p/uploads/${id}`,
};

const file = (bytes: number) =>
    Object.assign(new Blob([new Uint8Array(bytes)]), { name: 'scan.zip' });

const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });

/** A control plane and bucket in one fake fetch, recording every call. */
function fakeServer(
    overrides: { start?: Response; put?: (n: number) => Response } = {},
) {
    const calls: { method: string; url: string; body: unknown }[] = [];
    const fetch = vi.fn(async (url: string, init: RequestInit = {}) => {
        const method = init.method ?? 'GET';
        const body =
            typeof init.body === 'string' ? JSON.parse(init.body) : init.body;
        calls.push({ method, url, body });

        if (url === routes.start) {
            return (
                overrides.start ??
                json({ id: 'a1', partBytes: 4, parts: 3 }, 201)
            );
        }

        if (url.endsWith('/parts')) {
            const parts = (body as { parts: number[] }).parts;

            return json({
                urls: Object.fromEntries(
                    parts.map((n) => [n, `https://bucket/part-${n}`]),
                ),
            });
        }

        if (url.startsWith('https://bucket/')) {
            const n = Number(url.split('-')[1]);

            return (
                overrides.put?.(n) ??
                new Response(null, {
                    status: 200,
                    headers: { ETag: `"e${n}"` },
                })
            );
        }

        return url.endsWith('/complete')
            ? json({ status: 'processing' })
            : new Response(null, { status: 204 });
    });

    return { calls, fetch: fetch as unknown as typeof globalThis.fetch };
}

describe('direct upload', () => {
    it('starts, signs, sends every part and completes in part order', async () => {
        const server = fakeServer();
        const progress: number[] = [];

        const id = await directUpload({
            file: file(10),
            name: 'Scan',
            routes,
            fetch: server.fetch,
            xsrfToken: () => 'tok',
            batch: 2,
            onProgress: (f) => progress.push(f),
        });

        expect(id).toBe('a1');
        expect(server.calls[0]).toEqual({
            method: 'POST',
            url: routes.start,
            body: { name: 'Scan', filename: 'scan.zip', bytes: 10 },
        });
        expect(
            server.calls
                .filter((c) => c.url.endsWith('/parts'))
                .map((c) => c.body),
        ).toEqual([{ parts: [1, 2] }, { parts: [3] }]);
        expect(
            server.calls
                .filter((c) => c.method === 'PUT')
                .map((c) => (c.body as Blob).size)
                .sort((a, b) => a - b),
        ).toEqual([2, 4, 4]);
        expect(server.calls.at(-1)).toEqual({
            method: 'POST',
            url: routes.complete('a1'),
            body: {
                parts: [
                    { number: 1, etag: '"e1"' },
                    { number: 2, etag: '"e2"' },
                    { number: 3, etag: '"e3"' },
                ],
            },
        });
        expect(progress.at(-1)).toBe(1);
    });

    it('sends the XSRF token with control plane requests', async () => {
        const server = fakeServer();

        await directUpload({
            file: file(1),
            name: 'S',
            routes,
            fetch: server.fetch,
            xsrfToken: () => 'tok',
        });

        const init = (server.fetch as unknown as ReturnType<typeof vi.fn>).mock
            .calls[0][1] as RequestInit;
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe(
            'tok',
        );
    });

    it('reports validation errors from the start', async () => {
        const server = fakeServer({
            start: json(
                { message: 'Bad', errors: { filename: ['Upload a .glb'] } },
                422,
            ),
        });

        const upload = directUpload({
            file: file(1),
            name: 'S',
            routes,
            fetch: server.fetch,
            xsrfToken: () => null,
        });

        await expect(upload).rejects.toEqual(
            new DirectUploadError('Bad', { filename: 'Upload a .glb' }),
        );
        expect(server.calls).toHaveLength(1);
    });

    it('gives the upload up when a part fails', async () => {
        const server = fakeServer({
            put: (n) =>
                n === 2
                    ? new Response(null, { status: 500 })
                    : new Response(null, { headers: { ETag: '"x"' } }),
        });

        await expect(
            directUpload({
                file: file(10),
                name: 'S',
                routes,
                fetch: server.fetch,
                xsrfToken: () => null,
                concurrency: 1,
            }),
        ).rejects.toThrow('Part 2 failed (500).');
        expect(server.calls.at(-1)).toMatchObject({
            method: 'DELETE',
            url: routes.abort('a1'),
        });
    });

    it('explains a bucket that hides the ETag', async () => {
        const server = fakeServer({
            put: () => new Response(null, { status: 200 }),
        });

        await expect(
            directUpload({
                file: file(1),
                name: 'S',
                routes,
                fetch: server.fetch,
                xsrfToken: () => null,
            }),
        ).rejects.toThrow('CORS rule exposes it');
    });

    it('treats any other control plane error as a failure', async () => {
        const server = fakeServer({
            start: new Response(null, { status: 404 }),
        });

        await expect(
            directUpload({
                file: file(1),
                name: 'S',
                routes,
                fetch: server.fetch,
                xsrfToken: () => null,
            }),
        ).rejects.toThrow('The upload failed (404).');
    });
});
