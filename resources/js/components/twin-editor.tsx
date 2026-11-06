import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    destroy as destroyAsset,
    store as storeAsset,
} from '@/routes/twins/assets';
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
    kind: 'model' | 'tileset';
    name: string;
    originalName: string;
    bytes: number;
    files: number;
    url: string;
};

const FIELDS: { key: keyof Placement; label: string; step: number }[] = [
    { key: 'east', label: 'East (m)', step: 0.5 },
    { key: 'north', label: 'North (m)', step: 0.5 },
    { key: 'up', label: 'Up (m)', step: 0.1 },
    { key: 'headingDeg', label: 'Heading (°)', step: 5 },
    { key: 'scale', label: 'Scale', step: 0.1 },
];

const megabytes = (bytes: number) =>
    `${(bytes / 1024 / 1024).toFixed(bytes < 1024 * 1024 ? 2 : 1)} MB`;

/**
 * Uploads, placement and history for a twin. Placement edits show live in the
 * scene as drafts and are saved together as one new version.
 */
export function TwinEditor({
    twinId,
    definition,
    versions,
    assets,
    onClose,
}: {
    twinId: string;
    definition: TwinDefinition;
    versions: TwinVersionSummary[];
    assets: TwinAssetSummary[];
    onClose: () => void;
}) {
    const drafts = useTwin((s) => s.drafts);
    const placingLayer = useTwin((s) => s.placingLayer);
    const { setDraft, setPlacingLayer } = useTwin.getState();
    const [replaceErrors, setReplaceErrors] = useState<string[]>([]);
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
                    A model as .glb, or a 3D Tiles tileset zipped with
                    tileset.json at the top.
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
                        accept=".glb,.zip"
                        required
                        onChange={(e) => {
                            const file = e.target.files?.[0] ?? null;
                            upload.setData((data) => ({
                                ...data,
                                file,
                                name:
                                    data.name ||
                                    (file?.name.replace(/\.(glb|zip)$/i, '') ??
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
                {layers.length === 0 && (
                    <p className="muted hint">Nothing uploaded yet.</p>
                )}
                <ul className="editor-layers">
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
                                                ? ` · ${megabytes(asset.bytes)}`
                                                : ''}
                                        </span>
                                    </span>
                                    {asset && (
                                        <button
                                            className="link"
                                            onClick={() =>
                                                confirm(
                                                    `Remove ${layer.name}? Its files are deleted.`,
                                                ) &&
                                                router.delete(
                                                    destroyAsset.url([
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
