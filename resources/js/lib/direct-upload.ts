/**
 * Sends a file straight to the assets bucket in parts: the control plane
 * opens a multipart upload and signs a URL per part, the browser PUTs each
 * part, and the control plane joins them and queues the checks. Large scans
 * never pass through PHP, and a cancelled upload is given up on the server.
 */

export type DirectUploadRoutes = {
    start: string;
    parts: (id: string) => string;
    complete: (id: string) => string;
    abort: (id: string) => string;
};

export type DirectUploadOptions = {
    file: Blob & { name: string };
    name: string;
    routes: DirectUploadRoutes;
    onProgress?: (fraction: number) => void;
    signal?: AbortSignal;
    /** Parts in flight at once. */
    concurrency?: number;
    /** URLs signed per request. */
    batch?: number;
    fetch?: typeof fetch;
    xsrfToken?: () => string | null;
};

export class DirectUploadError extends Error {
    constructor(
        message: string,
        public readonly errors: Record<string, string> = {},
    ) {
        super(message);
    }
}

/** The token Laravel sets in the XSRF-TOKEN cookie, for JSON requests. */
export function cookieXsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

type Started = { id: string; partBytes: number; parts: number };

export async function directUpload(
    options: DirectUploadOptions,
): Promise<string> {
    const send = options.fetch ?? fetch.bind(globalThis);
    const token = (options.xsrfToken ?? cookieXsrfToken)();
    const concurrency = options.concurrency ?? 4;
    const batch = options.batch ?? 20;

    const json = async <T>(
        method: string,
        url: string,
        body?: unknown,
    ): Promise<T | null> => {
        const response = await send(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: method === 'DELETE' ? undefined : options.signal,
        });

        if (response.status === 422) {
            const payload = (await response.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const errors = Object.fromEntries(
                Object.entries(payload.errors ?? {}).map(([key, messages]) => [
                    key,
                    messages[0] ?? '',
                ]),
            );

            throw new DirectUploadError(
                payload.message ?? 'The upload was refused.',
                errors,
            );
        }

        if (!response.ok) {
            throw new DirectUploadError(
                `The upload failed (${response.status}).`,
            );
        }

        return response.status === 204 ? null : ((await response.json()) as T);
    };

    const started = (await json<Started>('POST', options.routes.start, {
        name: options.name,
        filename: options.file.name,
        bytes: options.file.size,
    })) as Started;

    try {
        const etags = new Map<number, string>();
        const urls = new Map<number, string>();
        const batches = new Map<number, Promise<void>>();
        let next = 1;
        let sent = 0;
        options.onProgress?.(0);

        const sign = async (from: number): Promise<void> => {
            const wanted = Array.from(
                { length: Math.min(batch, started.parts - from + 1) },
                (_, i) => from + i,
            );
            const signed = (await json<{ urls: Record<string, string> }>(
                'POST',
                options.routes.parts(started.id),
                {
                    parts: wanted,
                },
            )) as { urls: Record<string, string> };

            for (const [part, url] of Object.entries(signed.urls)) {
                urls.set(Number(part), url);
            }
        };

        const worker = async () => {
            while (next <= started.parts) {
                const part = next++;

                const first = Math.floor((part - 1) / batch) * batch + 1;

                if (!batches.has(first)) {
                    batches.set(first, sign(first));
                }

                await batches.get(first);

                const start = (part - 1) * started.partBytes;
                const chunk = options.file.slice(
                    start,
                    Math.min(start + started.partBytes, options.file.size),
                );
                const response = await send(urls.get(part) as string, {
                    method: 'PUT',
                    body: chunk,
                    signal: options.signal,
                });
                const etag = response.headers.get('ETag');

                if (!response.ok || !etag) {
                    throw new DirectUploadError(
                        response.ok
                            ? 'The bucket did not return an ETag; check its CORS rule exposes it.'
                            : `Part ${part} failed (${response.status}).`,
                    );
                }

                etags.set(part, etag);
                sent += chunk.size;
                options.onProgress?.(
                    options.file.size === 0 ? 1 : sent / options.file.size,
                );
            }
        };

        await Promise.all(
            Array.from(
                { length: Math.min(concurrency, started.parts) },
                worker,
            ),
        );

        await json('POST', options.routes.complete(started.id), {
            parts: [...etags]
                .sort(([a], [b]) => a - b)
                .map(([number, etag]) => ({ number, etag })),
        });
    } catch (error) {
        await json('DELETE', options.routes.abort(started.id)).catch(
            () => undefined,
        );

        throw error;
    }

    return started.id;
}
