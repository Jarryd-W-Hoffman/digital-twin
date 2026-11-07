import { Head, router, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { destroy, index, show, store } from '@/routes/organisation/types';
import type { BreadcrumbItem } from '@/types';

type LibraryType = {
    id: string;
    slug: string;
    name: string;
    color: string | null;
    shape: string | null;
    metrics: string[];
    states: number;
    reads: string[];
    author: string | null;
    updatedAt: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Types', href: index() }];

export default function Types({
    organisation,
    types,
    canManage,
}: {
    organisation: { id: string; name: string };
    types: LibraryType[];
    canManage: boolean;
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <>
            <Head title="Types" />
            <div className="flex max-w-3xl flex-col gap-8 p-4 md:p-6">
                <Heading
                    title="Type library"
                    description={`Custom asset types for every twin in ${organisation.name}. A twin takes a copy from its Layout panel, so changing a type here never changes a running twin.`}
                />

                {types.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No types yet. Import a JSON file, or save a type from a
                        twin's Layout panel.
                    </p>
                ) : (
                    <ul className="grid gap-3" aria-label="Types">
                        {types.map((t) => (
                            <li
                                key={t.id}
                                className="flex flex-wrap items-start gap-3 rounded-xl border p-4 text-sm"
                            >
                                <span
                                    aria-hidden
                                    className={`mt-1 size-4 shrink-0 border ${t.shape === 'cylinder' ? 'rounded-full' : 'rounded-sm'}`}
                                    style={{ background: t.color ?? undefined }}
                                />
                                <div className="grid min-w-0 flex-1 gap-1">
                                    <div className="font-medium">
                                        {t.name}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            · {t.slug}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground">
                                        {t.metrics.join(', ')}
                                        {t.states > 0 &&
                                            ` · ${t.states} ${t.states === 1 ? 'state' : 'states'}`}
                                    </div>
                                    {t.reads.length > 0 && (
                                        <div className="text-muted-foreground">
                                            Reads {t.reads.join(', ')}: a twin
                                            needs assets with those ids
                                        </div>
                                    )}
                                    <div className="text-xs text-muted-foreground">
                                        Saved{' '}
                                        {t.author ? `by ${t.author} ` : ''}
                                        {new Date(t.updatedAt).toLocaleString()}
                                    </div>
                                </div>
                                <div className="flex gap-1">
                                    <Button variant="ghost" size="sm" asChild>
                                        <a href={show.url(t.id)}>Download</a>
                                    </Button>
                                    {canManage && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                confirm(
                                                    `Delete ${t.name} from the library? Twins using it keep their copy.`,
                                                ) &&
                                                router.delete(
                                                    destroy.url(t.id),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Delete
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {canManage && (
                    <form
                        className="grid gap-4 rounded-xl border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(store.url(), {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <Heading
                            variant="small"
                            title="Import types"
                            description="A JSON file holding one type, a list of them, or a twin definition (its types are taken). A type with the same id as one here replaces it."
                        />
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="types-file">File</Label>
                                <Input
                                    id="types-file"
                                    type="file"
                                    accept="application/json,.json"
                                    required
                                    onChange={(e) =>
                                        form.setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Import
                            </Button>
                        </div>
                        {form.errors.file && (
                            <ul
                                className="grid gap-1 text-sm text-red-600 dark:text-red-400"
                                role="alert"
                            >
                                {form.errors.file.split('\n').map((error) => (
                                    <li key={error}>{error}</li>
                                ))}
                            </ul>
                        )}
                    </form>
                )}
            </div>
        </>
    );
}

Types.layout = [AppLayout, { breadcrumbs }];
