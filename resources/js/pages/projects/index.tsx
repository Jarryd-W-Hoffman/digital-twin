import { Head, Link } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { create, index, show } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';

type ProjectSummary = {
    id: string;
    name: string;
    description: string | null;
    twins: number;
    assets: number;
    updatedAt: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Projects', href: index() }];

const plural = (count: number, word: string) =>
    `${count} ${word}${count === 1 ? '' : 's'}`;

export default function ProjectsIndex({
    projects,
    canCreate,
}: {
    projects: ProjectSummary[];
    canCreate: boolean;
}) {
    return (
        <>
            <Head title="Projects" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Projects"
                        description="Each project holds its twins and a library of models, tilesets and scans they share."
                    />
                    {canCreate && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> New project
                            </Link>
                        </Button>
                    )}
                </div>

                {projects.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        No projects yet.
                        {canCreate
                            ? ' Create one to start uploading and building twins.'
                            : ' Ask an owner or admin to create one.'}
                    </div>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <Link
                                    href={show(project.id)}
                                    className="flex h-full flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-accent"
                                >
                                    <span className="flex items-center gap-2 font-medium">
                                        <FolderKanban className="size-4 text-muted-foreground" />
                                        {project.name}
                                    </span>
                                    {project.description && (
                                        <span className="line-clamp-2 text-sm text-muted-foreground">
                                            {project.description}
                                        </span>
                                    )}
                                    <span className="mt-auto text-xs text-muted-foreground">
                                        {plural(project.twins, 'twin')} ·{' '}
                                        {plural(project.assets, 'upload')} ·
                                        updated{' '}
                                        {new Date(
                                            project.updatedAt,
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

ProjectsIndex.layout = [AppLayout, { breadcrumbs }];
