import { Head, Link } from '@inertiajs/react';
import { Globe, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { create, index, show } from '@/routes/twins';
import type { BreadcrumbItem } from '@/types';

type TwinSummary = {
    id: string;
    name: string;
    description: string | null;
    version: number | null;
    project: { id: string; name: string };
    updatedAt: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Twins', href: index() }];

export default function TwinsIndex({
    twins,
    canCreate,
}: {
    twins: TwinSummary[];
    canCreate: boolean;
}) {
    return (
        <>
            <Head title="Twins" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Twins"
                        description="Every twin in this organisation, across its projects. Each save is kept as a version."
                    />
                    {canCreate && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> New twin
                            </Link>
                        </Button>
                    )}
                </div>

                {twins.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        No twins yet.
                        {canCreate
                            ? ' Start from a sample or upload a definition.'
                            : ' Ask an owner or admin to create one.'}
                    </div>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {twins.map((twin) => (
                            <li key={twin.id}>
                                <Link
                                    href={show(twin.id)}
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
                                        {twin.project.name} · version{' '}
                                        {twin.version ?? '-'} · updated{' '}
                                        {new Date(
                                            twin.updatedAt,
                                        ).toLocaleDateString()}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

TwinsIndex.layout = [AppLayout, { breadcrumbs }];
