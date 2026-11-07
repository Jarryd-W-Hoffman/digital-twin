import { Head, Link, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { DefinitionEditor } from '@/components/definition-editor';
import type { GatewayStatus } from '@/components/gateway-panel';
import {
    TwinEditor,
    type LibraryTypeSummary,
    type TwinAssetSummary,
    type TwinVersionSummary,
} from '@/components/twin-editor';
import { show as showProject } from '@/routes/projects';
import { index as twinReadings } from '@/routes/twins/readings';
import type { TwinDefinition } from '@/twin/core/types';
import {
    runDefinition,
    runGateway,
    stopTwin,
    useTwin,
    type Recorded,
} from '@/twin/state/store';
import { Viewer } from '@/twin/ui/Viewer';

type Props = {
    twin: { id: string; name: string; description: string | null };
    project: { id: string; name: string; topicPrefix: string };
    definition: TwinDefinition;
    version: number;
    versions: TwinVersionSummary[];
    assets: TwinAssetSummary[];
    types: LibraryTypeSummary[];
    gateway: GatewayStatus;
    can: { update: boolean; operate: boolean; manageTypes: boolean };
};

export default function ShowTwin({
    twin,
    project,
    definition,
    version,
    versions,
    assets,
    types,
    gateway,
    can,
}: Props) {
    const [editing, setEditing] = useState<'layout' | 'definition' | null>(
        null,
    );
    // Follow the gateway when one is connected, unless the user picks the simulator.
    const [preferSimulator, setPreferSimulator] = useState(false);
    const live =
        Boolean(gateway?.online && gateway.url && gateway.ticket) &&
        !preferSimulator;
    const gatewayUrl = gateway?.online ? gateway.url : null;

    // The gateway's status comes and goes; tickets are refreshed with it.
    usePoll(15_000, { only: ['gateway'] });
    const ticket = useRef(gateway?.ticket ?? null);
    useEffect(() => {
        if (gateway?.ticket) ticket.current = gateway.ticket;
    }, [gateway?.ticket]);

    // A save comes back as a new version; restart the simulator on it. In live
    // mode the gateway picks the version up itself.
    useEffect(() => {
        if (live && gatewayUrl && ticket.current) {
            const url = new URL(gatewayUrl);
            url.searchParams.set('ticket', ticket.current);
            runGateway(url.toString(), { canOperate: can.operate });
        } else {
            runDefinition(definition, { canOperate: can.operate });
        }
    }, [version, definition, can.operate, live, gatewayUrl]);

    // Recorded readings come from the control plane, whichever source runs.
    useEffect(() => {
        useTwin.setState({
            recorded: async (asset, metric, from, to) => {
                const res = await fetch(
                    twinReadings.url(twin.id, {
                        query: { asset, metric, from, to },
                    }),
                    { headers: { accept: 'application/json' } },
                );
                if (!res.ok) throw new Error('Could not load the history');
                return (await res.json()) as Recorded;
            },
        });
    }, [twin.id]);

    useEffect(() => stopTwin, []);

    return (
        <>
            <Head title={twin.name} />
            <Viewer
                header={
                    <>
                        <Link
                            href={showProject(project.id)}
                            className="back-link"
                            aria-label={`Back to ${project.name}`}
                            title={project.name}
                        >
                            ←
                        </Link>
                        <span className="twin-name">{twin.name}</span>
                        <span className="version-chip" title="Saved version">
                            v{version}
                        </span>
                        {gateway?.online && (
                            <div
                                className="segmented"
                                role="radiogroup"
                                aria-label="Source"
                            >
                                {(['Simulator', 'Live'] as const).map(
                                    (label) => {
                                        const on = (label === 'Live') === live;
                                        return (
                                            <button
                                                key={label}
                                                role="radio"
                                                aria-checked={on}
                                                className={on ? 'on' : ''}
                                                onClick={() =>
                                                    setPreferSimulator(
                                                        label === 'Simulator',
                                                    )
                                                }
                                            >
                                                {label}
                                            </button>
                                        );
                                    },
                                )}
                            </div>
                        )}
                        {can.update &&
                            (
                                [
                                    ['layout', 'Layout'],
                                    ['definition', 'Definition'],
                                ] as const
                            ).map(([panel, label]) => (
                                <button
                                    key={panel}
                                    className={
                                        editing === panel ? 'primary' : 'ghost'
                                    }
                                    aria-expanded={editing === panel}
                                    onClick={() =>
                                        setEditing(
                                            editing === panel ? null : panel,
                                        )
                                    }
                                >
                                    {label}
                                </button>
                            ))}
                    </>
                }
            >
                {editing === 'layout' && (
                    <TwinEditor
                        twinId={twin.id}
                        definition={definition}
                        versions={versions}
                        assets={assets}
                        types={types}
                        canManageTypes={can.manageTypes}
                        gateway={gateway}
                        topicPrefix={project.topicPrefix}
                        onClose={() => setEditing(null)}
                    />
                )}
                {editing === 'definition' && (
                    <DefinitionEditor
                        key={version}
                        twinId={twin.id}
                        definition={definition}
                        onClose={() => setEditing(null)}
                    />
                )}
            </Viewer>
        </>
    );
}
