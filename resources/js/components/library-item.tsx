import { router, useForm } from '@inertiajs/react';
import {
    Box,
    Image as ImageIcon,
    Layers,
    Loader2,
    Map as MapIcon,
    Mountain,
    RotateCcw,
    ScanLine,
    Shapes,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { destroy as destroyAsset } from '@/routes/projects/assets';
import { store as retryConversion } from '@/routes/projects/assets/conversion';
import { destroy as abortUpload } from '@/routes/projects/uploads';

export type LibraryAsset = {
    id: string;
    name: string;
    kind:
        | 'model'
        | 'tileset'
        | 'pointcloud'
        | 'terrain'
        | 'bim'
        | 'vector'
        | 'imagery'
        | 'mesh';
    /** A map layer's feature count. */
    features: number | null;
    originalName: string;
    bytes: number;
    files: number;
    status: 'uploading' | 'processing' | 'ready' | 'failed';
    error: string | null;
    progress: number | null;
    log: string | null;
    crs: string | null;
    /** An IFC model's element count. */
    elements: number | null;
    createdAt: string;
};

export const size = (bytes: number) =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : bytes < 1024 ** 3
          ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
          : `${(bytes / 1024 ** 3).toFixed(2)} GB`;

const ready: Record<LibraryAsset['kind'], (asset: LibraryAsset) => string> = {
    model: () => 'Model',
    tileset: (asset) => `Tileset · ${asset.files} files`,
    pointcloud: (asset) =>
        `Point cloud · ${asset.files} files · ${asset.crs ?? 'local metres'}`,
    terrain: (asset) =>
        `Terrain · ${asset.files} files · ${asset.crs ?? 'unknown CRS'}`,
    bim: (asset) =>
        `BIM · ${asset.elements ?? 0} elements${asset.crs ? ` · ${asset.crs}` : ''}`,
    vector: (asset) =>
        `Map layer · ${asset.features ?? 0} features · ${asset.crs ?? 'unknown CRS'}`,
    imagery: (asset) =>
        `Imagery · ${asset.files} files · ${asset.crs ?? 'unknown CRS'}`,
    mesh: (asset) =>
        `Mesh · ${asset.files} files · ${asset.crs ?? 'local metres'}`,
};

/** Kinds converted on the queue, which show progress and can be retried. */
const converted = (asset: LibraryAsset) =>
    asset.kind !== 'model' && asset.kind !== 'tileset';

function describe(asset: LibraryAsset): string {
    switch (asset.status) {
        case 'ready':
            return ready[asset.kind](asset);
        case 'failed':
            return 'Failed';
        case 'uploading':
            return 'Uploading';
        default:
            return converted(asset)
                ? `Converting ${asset.progress ?? 0}%`
                : 'Processing';
    }
}

function Icon({ asset }: { asset: LibraryAsset }) {
    const className = 'size-4 shrink-0 text-muted-foreground';

    if (asset.status === 'uploading' || asset.status === 'processing') {
        return <Loader2 className={`${className} animate-spin`} />;
    }

    if (asset.status === 'failed') {
        return <TriangleAlert className="size-4 shrink-0 text-destructive" />;
    }

    return asset.kind === 'pointcloud' ? (
        <ScanLine className={className} />
    ) : asset.kind === 'terrain' ? (
        <Mountain className={className} />
    ) : asset.kind === 'tileset' ? (
        <Layers className={className} />
    ) : asset.kind === 'vector' ? (
        <MapIcon className={className} />
    ) : asset.kind === 'imagery' ? (
        <ImageIcon className={className} />
    ) : asset.kind === 'mesh' ? (
        <Shapes className={className} />
    ) : (
        <Box className={className} />
    );
}

/**
 * Retrying a failed conversion, optionally with the EPSG code the file
 * didn't carry.
 */
function RetryConversion({
    projectId,
    asset,
}: {
    projectId: string;
    asset: LibraryAsset;
}) {
    const retry = useForm({ epsg: '' });

    return (
        <form
            className="mt-2 flex flex-wrap items-start gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                retry.post(retryConversion.url([projectId, asset.id]), {
                    preserveScroll: true,
                });
            }}
        >
            <div className="grid gap-1">
                <Input
                    aria-label={`EPSG code for ${asset.name}`}
                    className="h-8 w-36"
                    inputMode="numeric"
                    placeholder="EPSG (optional)"
                    value={retry.data.epsg}
                    onChange={(e) => retry.setData('epsg', e.target.value)}
                />
                <InputError message={retry.errors.epsg} />
            </div>
            <Button
                type="submit"
                size="sm"
                variant="outline"
                disabled={retry.processing}
            >
                <RotateCcw /> Retry
            </Button>
        </form>
    );
}

export default function LibraryItem({
    projectId,
    asset,
    canUpdate,
}: {
    projectId: string;
    asset: LibraryAsset;
    canUpdate: boolean;
}) {
    return (
        <li className="flex items-start gap-3 p-3 text-sm">
            <span className="mt-0.5">
                <Icon asset={asset} />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{asset.name}</span>
                <span className="block truncate text-xs text-muted-foreground">
                    {describe(asset)} · {size(asset.bytes)} ·{' '}
                    {asset.originalName}
                </span>
                {asset.error && (
                    <span className="block text-xs whitespace-pre-line text-destructive">
                        {asset.error}
                    </span>
                )}
                {asset.log && (
                    <details className="mt-1 text-xs text-muted-foreground">
                        <summary className="cursor-pointer">Log</summary>
                        <pre className="mt-1 max-h-40 overflow-auto rounded bg-muted p-2 whitespace-pre-wrap">
                            {asset.log}
                        </pre>
                    </details>
                )}
                {canUpdate && converted(asset) && asset.status === 'failed' && (
                    <RetryConversion projectId={projectId} asset={asset} />
                )}
            </span>
            {canUpdate && (
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Delete ${asset.name}`}
                    onClick={() =>
                        confirm(
                            `Delete ${asset.name}? It's removed from every twin in this project and its files are deleted.`,
                        ) &&
                        router.delete(
                            (asset.status === 'uploading'
                                ? abortUpload
                                : destroyAsset
                            ).url([projectId, asset.id]),
                            { preserveScroll: true },
                        )
                    }
                >
                    <Trash2 />
                </Button>
            )}
        </li>
    );
}
