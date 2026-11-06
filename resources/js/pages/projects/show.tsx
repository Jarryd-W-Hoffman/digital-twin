import { Head, Link, router, useForm } from '@inertiajs/react';
import { Box, Globe, Layers, Plus, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { destroy, index } from '@/routes/projects';
import {
    destroy as destroyAsset,
    store as storeAsset,
} from '@/routes/projects/assets';
import { create as createTwin, show as showTwin } from '@/routes/twins';
import type { BreadcrumbItem } from '@/types';

type TwinSummary = {
    id: string;
    name: string;
    description: string | null;
    version: number | null;
    updatedAt: string;
};

type AssetSummary = {
    id: string;
    name: string;
    kind: 'model' | 'tileset';
    originalName: string;
    bytes: number;
    files: number;
    createdAt: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Projects', href: index() }];

const size = (bytes: number) =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

export default function ShowProject({
    project,
    twins,
    assets,
    can,
}: {
    project: { id: string; name: string; description: string | null };
    twins: TwinSummary[];
    assets: AssetSummary[];
    can: { update: boolean };
}) {
    const upload = useForm<{ name: string; file: File | null }>({
        name: '',
        file: null,
    });

    return (
        <>
            <Head title={project.name} />
            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={project.name}
                        description={project.description ?? undefined}
                    />
                    {can.update && (
                        <div className="flex gap-2">
                            <Button asChild>
                                <Link
                                    href={createTwin({
                                        query: { project: project.id },
                                    })}
                                >
                                    <Plus /> New twin
                                </Link>
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    confirm(
                                        `Delete ${project.name}, its twins and every upload in its library? This can't be undone.`,
                                    ) && router.delete(destroy.url(project.id))
                                }
                            >
                                <Trash2 /> Delete
                            </Button>
                        </div>
                    )}
                </div>

                <section className="grid gap-3" aria-labelledby="twins-heading">
                    <h2 id="twins-heading" className="text-lg font-medium">
                        Twins
                    </h2>
                    {twins.length === 0 ? (
                        <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            No twins in this project yet.
                        </div>
                    ) : (
                        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {twins.map((twin) => (
                                <li key={twin.id}>
                                    <Link
                                        href={showTwin(twin.id)}
                                        className="flex h-full flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-accent"
                                    >
                                        <span className="flex items-center gap-2 font-medium">
                                            <Globe className="size-4 text-muted-foreground" />
                                            {twin.name}
                                        </span>
                                        {twin.description && (
                                            <span className="line-clamp-2 text-sm text-muted-foreground">
                                                {twin.description}
                                            </span>
                                        )}
                                        <span className="mt-auto text-xs text-muted-foreground">
                                            Version {twin.version ?? '-'} ·
                                            updated{' '}
                                            {new Date(
                                                twin.updatedAt,
                                            ).toLocaleDateString()}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    className="grid gap-3"
                    aria-labelledby="library-heading"
                >
                    <div>
                        <h2
                            id="library-heading"
                            className="text-lg font-medium"
                        >
                            Library
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Models and tilesets any twin in this project can
                            show. Add them from a twin's Layout panel.
                        </p>
                    </div>

                    {can.update && (
                        <form
                            className="flex flex-wrap items-end gap-3 rounded-xl border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                upload.post(storeAsset.url(project.id), {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => upload.reset(),
                                });
                            }}
                        >
                            <div className="grid min-w-48 flex-1 gap-2">
                                <Label htmlFor="upload-file">
                                    File (.glb, or a zipped 3D Tiles tileset)
                                </Label>
                                <Input
                                    id="upload-file"
                                    type="file"
                                    accept=".glb,.zip"
                                    required
                                    onChange={(e) => {
                                        const file =
                                            e.target.files?.[0] ?? null;
                                        upload.setData((data) => ({
                                            ...data,
                                            file,
                                            name:
                                                data.name ||
                                                (file?.name.replace(
                                                    /\.(glb|zip)$/i,
                                                    '',
                                                ) ??
                                                    ''),
                                        }));
                                    }}
                                />
                                <InputError message={upload.errors.file} />
                            </div>
                            <div className="grid min-w-48 flex-1 gap-2">
                                <Label htmlFor="upload-name">Name</Label>
                                <Input
                                    id="upload-name"
                                    required
                                    value={upload.data.name}
                                    onChange={(e) =>
                                        upload.setData('name', e.target.value)
                                    }
                                />
                                <InputError message={upload.errors.name} />
                            </div>
                            <Button type="submit" disabled={upload.processing}>
                                {upload.progress
                                    ? `Uploading ${upload.progress.percentage ?? 0}%`
                                    : 'Upload'}
                            </Button>
                        </form>
                    )}

                    {assets.length === 0 ? (
                        <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            Nothing uploaded yet.
                        </div>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {assets.map((asset) => (
                                <li
                                    key={asset.id}
                                    className="flex items-center gap-3 p-3 text-sm"
                                >
                                    {asset.kind === 'tileset' ? (
                                        <Layers className="size-4 shrink-0 text-muted-foreground" />
                                    ) : (
                                        <Box className="size-4 shrink-0 text-muted-foreground" />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {asset.name}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {asset.kind === 'tileset'
                                                ? `Tileset · ${asset.files} files`
                                                : 'Model'}{' '}
                                            · {size(asset.bytes)} ·{' '}
                                            {asset.originalName}
                                        </span>
                                    </span>
                                    {can.update && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Delete ${asset.name}`}
                                            onClick={() =>
                                                confirm(
                                                    `Delete ${asset.name}? It's removed from every twin in this project and its files are deleted.`,
                                                ) &&
                                                router.delete(
                                                    destroyAsset.url([
                                                        project.id,
                                                        asset.id,
                                                    ]),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

ShowProject.layout = [AppLayout, { breadcrumbs }];
