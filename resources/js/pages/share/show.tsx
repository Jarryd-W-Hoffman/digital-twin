import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { readings, ticket } from '@/routes/share';
import type { TwinDefinition } from '@/twin/core/types';
import { parseView } from '@/twin/core/views';
import {
    applyView,
    runDefinition,
    runGateway,
    stopTwin,
    useTwin,
    type Recorded,
} from '@/twin/state/store';
import { Viewer } from '@/twin/ui/Viewer';

type Props = {
    twin: { name: string; description: string | null };
    organisation: string;
    token: string;
    /** Whether the link shows live readings and history. */
    live: boolean;
    expiresAt: string;
    definition: TwinDefinition;
    startView: unknown;
};

/** Where the twin's gateway is, with a new ticket; null to stop following it. */
async function gatewayUrl(token: string): Promise<string | null> {
    const res = await fetch(ticket.url(token), {
        headers: { accept: 'application/json' },
    });
    if (!res.ok) return null;
    return ((await res.json()) as { url: string | null }).url;
}

/**
 * A twin shown through a share link: the scene, read only, following the
 * gateway live when the link allows and it's online, and simulating in the
 * browser otherwise.
 */
export default function SharedTwin({
    twin,
    organisation,
    token,
    live,
    expiresAt,
    definition,
    startView,
}: Props) {
    useEffect(() => {
        let cancelled = false;
        runDefinition(definition, { canOperate: false });
        const view = parseView(startView);
        if (view) applyView(view);

        if (live) {
            useTwin.setState({
                recorded: async (asset, metric, from, to) => {
                    const res = await fetch(
                        readings.url(token, {
                            query: { asset, metric, from, to },
                        }),
                        { headers: { accept: 'application/json' } },
                    );
                    if (!res.ok) throw new Error('Could not load the history');
                    return (await res.json()) as Recorded;
                },
            });
            void gatewayUrl(token).then((url) => {
                if (url && !cancelled)
                    runGateway(url, {
                        canOperate: false,
                        refresh: () => gatewayUrl(token),
                    });
            });
        }

        return () => {
            cancelled = true;
            stopTwin();
        };
    }, [definition, live, startView, token]);

    const until = new Date(expiresAt).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

    return (
        <>
            <Head title={twin.name}>
                <meta name="referrer" content="no-referrer" />
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <Viewer
                header={
                    <>
                        <span className="twin-name">{twin.name}</span>
                        <span
                            className="version-chip"
                            title={`Shared by ${organisation} until ${until}`}
                        >
                            Shared · read only
                        </span>
                    </>
                }
            />
        </>
    );
}
