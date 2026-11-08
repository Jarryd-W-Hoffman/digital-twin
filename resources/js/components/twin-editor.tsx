import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { GatewayPanel, type GatewayStatus } from '@/components/gateway-panel';
import { OpcUaPanel, type OpcUaWrites } from '@/components/opcua-panel';
import { TelemetryPanel } from '@/components/telemetry-panel';
import { index as typeLibrary } from '@/routes/organisation/types';
import { store as storeAsset } from '@/routes/twins/assets';
import {
    destroy as removeLayer,
    store as addLayer,
} from '@/routes/twins/layers';
import { store as addType } from '@/routes/twins/types';
import { update as saveTypeToLibrary } from '@/routes/twins/types/library';
import {
    restore,
    show as showVersion,
    store as storeVersion,
} from '@/routes/twins/versions';
import { ORIGIN, savedPlacement, withPlacements } from '@/twin/core/layers';
import type { Placement, TwinDefinition } from '@/twin/core/types';
import { validateDefinition } from '@/twin/core/validate';
import { useTwin } from '@/twin/state/store';

export type TwinVersionSummary = {
    id: string;
    number: number;
    note: string | null;
    author: string | null;
    createdAt: string;
};

export type TwinAssetSummary = {
    id: string;
    kind: 'model' | 'tileset' | 'pointcloud' | 'terrain' | 'bim';
    name: string;
    originalName: string;
    bytes: number;
    files: number;
    url: string;
};

/** A type in the organisation's library, and how it compares with this twin's type of that id. */
export type LibraryTypeSummary = {
    id: string;
    slug: string;
    name: string;
    inTwin: 'same' | 'different' | null;
};

const FIELDS: { key: keyof Placement; label: string; step: number }[] = [
    { key: 'east', label: 'East (m)', step: 0.5 },
    { key: 'north', label: 'North (m)', step: 0.5 },
    { key: 'up', label: 'Up (m)', step: 0.1 },
    { key: 'headingDeg', label: 'Heading (°)', step: 5 },
    { key: 'scale', label: 'Scale', step: 0.1 },
];

const size = (bytes: number) =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
/**
 * Uploads, the project library, placement and history for a twin. Placement edits show live in the
 * scene as drafts and are saved together as one new version.
 */
export function TwinEditor({
    twinId,
    definition,
    versions,
    assets,
    types,
    canManageTypes,
    gateway,
    topicPrefix,
    opcuaServers,
    opcuaWrites,
    onClose,
}: {
    twinId: string;
    gateway: GatewayStatus;
    topicPrefix: string;
    opcuaServers: string[];
    opcuaWrites: OpcUaWrites;
    definition: TwinDefinition;
    versions: TwinVersionSummary[];
    assets: TwinAssetSummary[];
    types: LibraryTypeSummary[];
    canManageTypes: boolean;
    onClose: () => void;
}) {
    const drafts = useTwin((s) => s.drafts);
    const placingLayer = useTwin((s) => s.placingLayer);
    const { setDraft, setPlacingLayer } = useTwin.getState();
    const [replaceErrors, setReplaceErrors] = useState<string[]>([]);
    const [typeErrors, setTypeErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const upload = useForm<{ name: string; file: File | null }>({
        name: '',
        file: null,
    });

    const layers = [
        ...(definition.models ?? []).map((l) => ({
            ...l,
            kind: 'Model' as const,
        })),
        ...(definition.tilesets ?? []).map((l) => ({
            ...l,
            kind: 'Tileset' as const,
        })),
    ];
    const dirty = Object.keys(drafts).length > 0;
    const terrain = definition.terrain;
    const terrainAsset = assets.find((a) => a.id === terrain?.assetId);
    // Library uploads this twin doesn't show yet.
    const unused = assets.filter(
        (a) =>
            a.id !== terrain?.assetId &&
            !layers.some((layer) => layer.assetId === a.id),
    );

    const twinTypes = definition.types ?? [];
    const libraryOnly = types.filter((t) => t.inTwin === null);
    const takeType = (id: string) =>
        router.post(
            addType.url(twinId),
            { type: id },
            {
                preserveScroll: true,
                onStart: () => setTypeErrors([]),
                onError: (e) => setTypeErrors(String(e.type ?? '').split('\n')),
            },
        );

    const save = () => {
        setSaving(true);
        router.post(
            storeVersion.url(twinId),
            {
                definition: withPlacements(
                    definition,
                    drafts,
                ) as unknown as Record<string, never>,
                note: 'Moved layers',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    const replaceDefinition = async (file: File) => {
        let parsed: unknown;
        try {
            parsed = JSON.parse(await file.text());
        } catch {
            setReplaceErrors(['That file is not valid JSON.']);
            return;
        }
        const errors = validateDefinition(parsed);
        setReplaceErrors(errors);
        if (errors.length === 0) {
            router.post(
                storeVersion.url(twinId),
                {
                    definition: parsed as Record<string, never>,
                    note: `Replaced from ${file.name}`,
                },
                {
                    preserveScroll: true,
                    onError: (e) =>
                        setReplaceErrors(
                            String(e.definition ?? '').split('\n'),
                        ),
                },
            );
        }
    };

    return (
        <aside className="panel editor" aria-label="Layout">
            <div className="editor-head">
                <h2>Layout</h2>
                <button
                    className="ghost"
                    onClick={onClose}
                    aria-label="Close layout"
                >
                    ✕
                </button>
            </div>

            <section>
                <h3>Upload</h3>
                <p className="muted hint">
                    A model as .glb, a 3D Tiles tileset zipped with tileset.json
                    at the top, or a LAS, LAZ or E57 point cloud. It goes into
                    the project's library and is added here; a point cloud is
                    converted first, then added from the library.
                </p>
                <form
                    className="editor-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        upload.post(storeAsset.url(twinId), {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => upload.reset(),
                        });
                    }}
                >
                    <input
                        aria-label="Upload name"
                        placeholder="Name"
                        required
                        value={upload.data.name}
                        onChange={(e) => upload.setData('name', e.target.value)}
                    />
                    <input
                        aria-label="Upload file"
                        type="file"
                        accept=".glb,.zip,.las,.laz,.e57,.tif,.tiff,.asc,.ifc"
                        required
                        onChange={(e) => {
                            const file = e.target.files?.[0] ?? null;
                            upload.setData((data) => ({
                                ...data,
                                file,
                                name:
                                    data.name ||
                                    (file?.name.replace(
                                        /\.(glb|zip|las|laz|e57|tif|tiff|asc)$/i,
                                        '',
                                    ) ??
                                        ''),
                            }));
                        }}
                    />
                    <button
                        type="submit"
                        className="primary"
                        disabled={upload.processing}
                    >
                        {upload.progress
                            ? `Uploading ${upload.progress.percentage ?? 0}%`
                            : 'Upload'}
                    </button>
                    {(upload.errors.file || upload.errors.name) && (
                        <p className="editor-error" role="alert">
                            {upload.errors.file ?? upload.errors.name}
                        </p>
                    )}
                </form>
            </section>

            <section>
                <h3>Layers</h3>
                {layers.length === 0 && !terrain && (
                    <p className="muted hint">Nothing uploaded yet.</p>
                )}
                <ul className="editor-layers">
                    {terrain && (
                        <li>
                            <div className="editor-layer-head">
                                <span>
                                    <strong>{terrain.name ?? 'Terrain'}</strong>
                                    <span className="muted">
                                        {' '}
                                        · Terrain
                                        {terrainAsset
                                            ? ` · ${size(terrainAsset.bytes)}`
                                            : ''}
                                    </span>
                                </span>
                                {terrainAsset && (
                                    <button
                                        className="link"
                                        onClick={() =>
                                            confirm(
                                                `Remove ${terrainAsset.name} from this twin? The site goes back to the plain globe, and the terrain stays in the project library.`,
                                            ) &&
                                            router.delete(
                                                removeLayer.url([
                                                    twinId,
                                                    terrainAsset.id,
                                                ]),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remove
                                    </button>
                                )}
                            </div>
                        </li>
                    )}
                    {layers.map((layer) => {
                        const asset = assets.find(
                            (a) => a.id === layer.assetId,
                        );
                        const draft =
                            layer.id in drafts
                                ? drafts[layer.id]
                                : savedPlacement(definition, layer.id);
                        const placed = draft !== undefined;
                        return (
                            <li key={layer.id}>
                                <div className="editor-layer-head">
                                    <span>
                                        <strong>{layer.name}</strong>
                                        <span className="muted">
                                            {' '}
                                            · {layer.kind}
                                            {asset
                                                ? ` · ${size(asset.bytes)}`
                                                : ''}
                                        </span>
                                    </span>
                                    {asset && (
                                        <button
                                            className="link"
                                            onClick={() =>
                                                confirm(
                                                    `Remove ${layer.name} from this twin? It stays in the project library.`,
                                                ) &&
                                                router.delete(
                                                    removeLayer.url([
                                                        twinId,
                                                        asset.id,
                                                    ]),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>
                                {layer.kind === 'Tileset' && (
                                    <label className="editor-check">
                                        <input
                                            type="checkbox"
                                            checked={placed}
                                            onChange={(e) =>
                                                setDraft(
                                                    layer.id,
                                                    e.target.checked
                                                        ? ORIGIN
                                                        : undefined,
                                                )
                                            }
                                        />
                                        Built in local metres (place it here)
                                    </label>
                                )}
                                {draft && (
                                    <div className="editor-placement">
                                        {FIELDS.map((f) => (
                                            <label key={f.key}>
                                                <span>{f.label}</span>
                                                <input
                                                    type="number"
                                                    step={f.step}
                                                    min={
                                                        f.key === 'scale'
                                                            ? 0.001
                                                            : undefined
                                                    }
                                                    value={draft[f.key]}
                                                    onChange={(e) => {
                                                        const value = Number(
                                                            e.target.value,
                                                        );
                                                        if (
                                                            Number.isFinite(
                                                                value,
                                                            ) &&
                                                            (f.key !==
                                                                'scale' ||
                                                                value > 0)
                                                        ) {
                                                            setDraft(layer.id, {
                                                                ...draft,
                                                                [f.key]: value,
                                                            });
                                                        }
                                                    }}
                                                />
                                            </label>
                                        ))}
                                        <button
                                            className={
                                                placingLayer === layer.id
                                                    ? 'primary'
                                                    : ''
                                            }
                                            onClick={() =>
                                                setPlacingLayer(
                                                    placingLayer === layer.id
                                                        ? null
                                                        : layer.id,
                                                )
                                            }
                                        >
                                            {placingLayer === layer.id
                                                ? 'Click the ground…'
                                                : 'Place on map'}
                                        </button>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
                {dirty && (
                    <div className="editor-actions">
                        <button
                            className="primary"
                            onClick={save}
                            disabled={saving}
                        >
                            Save layout
                        </button>
                        <button
                            className="ghost"
                            onClick={() =>
                                useTwin.setState({
                                    drafts: {},
                                    placingLayer: null,
                                })
                            }
                        >
                            Discard
                        </button>
                    </div>
                )}
            </section>

            <section>
                <h3>Library</h3>
                {unused.length === 0 ? (
                    <p className="muted hint">
                        Every upload in this project is shown here.
                    </p>
                ) : (
                    <ul className="editor-layers" aria-label="Library">
                        {unused.map((asset) => (
                            <li key={asset.id}>
                                <div className="editor-layer-head">
                                    <span>
                                        <strong>{asset.name}</strong>
                                        <span className="muted">
                                            {' '}
                                            ·{' '}
                                            {asset.kind === 'pointcloud'
                                                ? 'point cloud'
                                                : asset.kind === 'bim'
                                                  ? 'BIM model'
                                                  : asset.kind}{' '}
                                            · {size(asset.bytes)}
                                        </span>
                                    </span>
                                    <button
                                        className="link"
                                        onClick={() =>
                                            router.post(
                                                addLayer.url(twinId),
                                                { asset: asset.id },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {asset.kind === 'terrain'
                                            ? terrain
                                                ? 'Use as terrain instead'
                                                : 'Use as terrain'
                                            : 'Add'}
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section>
                <h3>Types</h3>
                <p className="muted hint">
                    Custom asset types this twin defines, and the ones in the
                    organisation's <a href={typeLibrary.url()}>type library</a>.
                    Both ways copy, so changing one never changes the other.
                </p>
                {twinTypes.length > 0 && (
                    <ul className="editor-layers" aria-label="Twin types">
                        {twinTypes.map((type) => {
                            const entry = types.find((t) => t.slug === type.id);
                            return (
                                <li key={type.id}>
                                    <div className="editor-layer-head">
                                        <span>
                                            <strong>{type.name}</strong>
                                            <span className="muted">
                                                {' '}
                                                · {type.id}
                                                {entry?.inTwin === 'same' &&
                                                    ' · in the library'}
                                                {entry?.inTwin ===
                                                    'different' &&
                                                    ' · differs from the library'}
                                            </span>
                                        </span>
                                        <span className="editor-layer-actions">
                                            {entry?.inTwin === 'different' && (
                                                <button
                                                    className="link"
                                                    onClick={() =>
                                                        confirm(
                                                            `Replace this twin's ${type.name} with the library's?`,
                                                        ) && takeType(entry.id)
                                                    }
                                                >
                                                    Take library's
                                                </button>
                                            )}
                                            {canManageTypes &&
                                                entry?.inTwin !== 'same' && (
                                                    <button
                                                        className="link"
                                                        onClick={() =>
                                                            (!entry ||
                                                                confirm(
                                                                    `Replace the library's ${entry.name} with this twin's?`,
                                                                )) &&
                                                            router.put(
                                                                saveTypeToLibrary.url(
                                                                    [
                                                                        twinId,
                                                                        type.id,
                                                                    ],
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Save to library
                                                    </button>
                                                )}
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {libraryOnly.length > 0 && (
                    <ul className="editor-layers" aria-label="Type library">
                        {libraryOnly.map((type) => (
                            <li key={type.id}>
                                <div className="editor-layer-head">
                                    <span>
                                        <strong>{type.name}</strong>
                                        <span className="muted">
                                            {' '}
                                            · {type.slug} · library
                                        </span>
                                    </span>
                                    <button
                                        className="link"
                                        onClick={() => takeType(type.id)}
                                    >
                                        Add
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {twinTypes.length === 0 && libraryOnly.length === 0 && (
                    <p className="muted hint">
                        No types yet. Import some into the library, or define
                        them in the definition.
                    </p>
                )}
                {typeErrors.length > 0 && (
                    <ul className="editor-error" role="alert">
                        {typeErrors.map((error) => (
                            <li key={error}>{error}</li>
                        ))}
                    </ul>
                )}
            </section>

            <GatewayPanel twinId={twinId} gateway={gateway} />

            <TelemetryPanel
                key={versions[0]?.id}
                twinId={twinId}
                definition={definition}
                topicPrefix={topicPrefix}
            />

            <OpcUaPanel
                key={`opcua-${versions[0]?.id}`}
                twinId={twinId}
                definition={definition}
                servers={opcuaServers}
                writes={opcuaWrites}
            />

            <section>
                <h3>Definition</h3>
                <div className="editor-actions">
                    <a
                        className="button-like"
                        href={showVersion.url([twinId, versions[0]?.id ?? ''])}
                    >
                        Download JSON
                    </a>
                    <label className="button-like">
                        Replace…
                        <input
                            type="file"
                            accept="application/json,.json"
                            hidden
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file) void replaceDefinition(file);
                                e.target.value = '';
                            }}
                        />
                    </label>
                </div>
                {replaceErrors.length > 0 && (
                    <ul className="editor-error" role="alert">
                        {replaceErrors.map((error) => (
                            <li key={error}>{error}</li>
                        ))}
                    </ul>
                )}
            </section>

            <section>
                <h3>History</h3>
                <ol className="editor-versions">
                    {versions.map((v, i) => (
                        <li key={v.id}>
                            <span>
                                <strong>v{v.number}</strong> {v.note ?? 'Saved'}
                                <span className="muted">
                                    {' '}
                                    · {v.author ?? 'system'} ·{' '}
                                    {new Date(v.createdAt).toLocaleString()}
                                </span>
                            </span>
                            {i > 0 && (
                                <button
                                    className="link"
                                    onClick={() =>
                                        router.post(
                                            restore.url([twinId, v.id]),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Restore
                                </button>
                            )}
                        </li>
                    ))}
                </ol>
            </section>
        </aside>
    );
}
