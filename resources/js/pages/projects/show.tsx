import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Globe, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import LibraryItem from '@/components/library-item';
import type { LibraryAsset } from '@/components/library-item';
import ProjectDevices from '@/components/project-devices';
import type { ProjectDevice, ProjectMqtt } from '@/components/project-devices';
import ProjectOpcUaServers from '@/components/project-opcua-servers';
import type { ProjectOpcUaServer } from '@/components/project-opcua-servers';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { DirectUploadError, directUpload } from '@/lib/direct-upload';
import { destroy, index } from '@/routes/projects';
import { store as storeAsset } from '@/routes/projects/assets';
import {
    complete as completeUpload,
    destroy as abortUpload,
    parts as uploadParts,
    store as startUpload,
} from '@/routes/projects/uploads';
import { create as createTwin, show as showTwin } from '@/routes/twins';
import type { BreadcrumbItem } from '@/types';

type TwinSummary = {
    id: string;
    name: string;
    description: string | null;
    version: number | null;
    updatedAt: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Projects', href: index() }];

const pointCloud = /\.(las|laz|e57)$/i;
const terrain = /\.(tif|tiff|asc)$/i;

export default function ShowProject({
    project,
    twins,
    assets,
    devices,
    opcuaServers,
    mqtt,
    directUploads,
    can,
}: {
    project: { id: string; name: string; description: string | null };
    twins: TwinSummary[];
    assets: LibraryAsset[];
    devices: ProjectDevice[];
    opcuaServers: ProjectOpcUaServer[];
    mqtt: ProjectMqtt;
    directUploads: boolean;
    can: { update: boolean };
}) {
    const upload = useForm<{
        name: string;
        file: File | null;
        epsg: string;
        spacing: string;
    }>({
        name: '',
        file: null,
        epsg: '',
        spacing: '',
    });
    const isPointCloud = pointCloud.test(upload.data.file?.name ?? '');
    const isTerrain = terrain.test(upload.data.file?.name ?? '');
    const [direct, setDirect] = useState<{ percent: number } | null>(null);
    const cancel = useRef<AbortController | null>(null);
    const busy = assets.some(
        (asset) =>
            asset.status === 'uploading' || asset.status === 'processing',
    );
    const poll = usePoll(3000, { only: ['assets'] }, { autoStart: false });

    useEffect(() => {
        if (busy) {
            poll.start();
        } else {
            poll.stop();
        }
    }, [busy, poll]);

    const submit = async () => {
        if (!directUploads || !upload.data.file) {
            upload.post(storeAsset.url(project.id), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => upload.reset(),
            });

            return;
        }

        cancel.current = new AbortController();
        upload.clearErrors();
        setDirect({ percent: 0 });

        try {
            await directUpload({
                file: upload.data.file,
                name: upload.data.name,
                fields: isPointCloud
                    ? {
                          epsg: upload.data.epsg || null,
                          spacing: upload.data.spacing || null,
                      }
                    : isTerrain
                      ? { epsg: upload.data.epsg || null }
                      : {},
                signal: cancel.current.signal,
                onProgress: (fraction) =>
                    setDirect({ percent: Math.round(fraction * 100) }),
                routes: {
                    start: startUpload.url(project.id),
                    parts: (id) => uploadParts.url([project.id, id]),
                    complete: (id) => completeUpload.url([project.id, id]),
                    abort: (id) => abortUpload.url([project.id, id]),
                },
            });
            upload.reset();
        } catch (error) {
            if (!cancel.current.signal.aborted) {
                const errors =
                    error instanceof DirectUploadError ? error.errors : {};
                upload.setError({
                    name: errors.name,
                    epsg: errors.epsg,
                    spacing: errors.spacing,
                    file:
                        errors.filename ??
                        errors.bytes ??
                        (error instanceof Error
                            ? error.message
                            : 'The upload failed.'),
                });
            }
        } finally {
            setDirect(null);
            router.reload({ only: ['assets'] });
        }
    };

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
                            Models, tilesets and point clouds any twin in this
                            project can show. Point clouds are converted to 3D
                            Tiles first. Add them from a twin's Layout panel.
                        </p>
                    </div>

                    {can.update && (
                        <form
                            className="flex flex-wrap items-end gap-3 rounded-xl border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void submit();
                            }}
                        >
                            <div className="grid min-w-48 flex-1 gap-2">
                                <Label htmlFor="upload-file">
                                    File (.glb, a zipped 3D Tiles tileset, a
                                    .las, .laz or .e57 point cloud, a .tif or
                                    .asc elevation model, or an .ifc building
                                    model)
                                </Label>
                                <Input
                                    id="upload-file"
                                    type="file"
                                    accept=".glb,.zip,.las,.laz,.e57,.tif,.tiff,.asc,.ifc"
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
                                                    /\.(glb|zip|las|laz|e57|tif|tiff|asc)$/i,
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
                            {(isPointCloud || isTerrain) && (
                                <>
                                    <div className="grid w-40 gap-2">
                                        <Label htmlFor="upload-epsg">
                                            EPSG code
                                        </Label>
                                        <Input
                                            id="upload-epsg"
                                            inputMode="numeric"
                                            placeholder="From the file"
                                            value={upload.data.epsg}
                                            onChange={(e) =>
                                                upload.setData(
                                                    'epsg',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={upload.errors.epsg}
                                        />
                                    </div>
                                    {isPointCloud && (
                                        <div className="grid w-40 gap-2">
                                            <Label htmlFor="upload-spacing">
                                                Thin to spacing (m)
                                            </Label>
                                            <Input
                                                id="upload-spacing"
                                                inputMode="decimal"
                                                placeholder="Keep every point"
                                                value={upload.data.spacing}
                                                onChange={(e) =>
                                                    upload.setData(
                                                        'spacing',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={upload.errors.spacing}
                                            />
                                        </div>
                                    )}
                                </>
                            )}
                            <Button
                                type="submit"
                                disabled={upload.processing || direct !== null}
                            >
                                {direct
                                    ? `Uploading ${direct.percent}%`
                                    : upload.progress
                                      ? `Uploading ${upload.progress.percentage ?? 0}%`
                                      : 'Upload'}
                            </Button>
                            {direct && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => cancel.current?.abort()}
                                >
                                    Cancel
                                </Button>
                            )}
                        </form>
                    )}

                    {assets.length === 0 ? (
                        <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            Nothing uploaded yet.
                        </div>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {assets.map((asset) => (
                                <LibraryItem
                                    key={asset.id}
                                    projectId={project.id}
                                    asset={asset}
                                    canUpdate={can.update}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <ProjectDevices
                    projectId={project.id}
                    devices={devices}
                    mqtt={mqtt}
                    canUpdate={can.update}
                />

                <ProjectOpcUaServers
                    projectId={project.id}
                    servers={opcuaServers}
                    canUpdate={can.update}
                />
            </div>
        </>
    );
}

ShowProject.layout = [AppLayout, { breadcrumbs }];
