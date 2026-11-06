import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    TwinEditor,
    type TwinAssetSummary,
    type TwinVersionSummary,
} from '@/components/twin-editor';
import { index } from '@/routes/twins';
import type { TwinDefinition } from '@/twin/core/types';
import { runDefinition, stopTwin } from '@/twin/state/store';
import { Viewer } from '@/twin/ui/Viewer';

type Props = {
    twin: { id: string; name: string; description: string | null };
    definition: TwinDefinition;
    version: number;
    versions: TwinVersionSummary[];
    assets: TwinAssetSummary[];
    can: { update: boolean; operate: boolean };
};

export default function ShowTwin({
    twin,
    definition,
    version,
    versions,
    assets,
    can,
}: Props) {
    const [editing, setEditing] = useState(false);

    // A save comes back as a new version; restart the simulator on it.
    useEffect(() => {
        runDefinition(definition, { canOperate: can.operate });
    }, [version, definition, can.operate]);

    useEffect(() => stopTwin, []);

    return (
        <>
            <Head title={twin.name} />
            <Viewer
                header={
                    <>
                        <Link
                            href={index()}
                            className="back-link"
                            aria-label="All twins"
                        >
                            ←
                        </Link>
                        <span className="twin-name">{twin.name}</span>
                        <span className="version-chip" title="Saved version">
                            v{version}
                        </span>
                        {can.update && (
                            <button
                                className={editing ? 'primary' : 'ghost'}
                                aria-expanded={editing}
                                onClick={() => setEditing(!editing)}
                            >
                                Layout
                            </button>
                        )}
                    </>
                }
            >
                {editing && (
                    <TwinEditor
                        twinId={twin.id}
                        definition={definition}
                        versions={versions}
                        assets={assets}
                        onClose={() => setEditing(false)}
                    />
                )}
            </Viewer>
        </>
    );
}
