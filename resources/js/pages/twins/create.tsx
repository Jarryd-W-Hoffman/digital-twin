import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import TwinController from '@/actions/App/Http/Controllers/TwinController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { create as createProject } from '@/routes/projects';
import { create, index } from '@/routes/twins';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Twins', href: index() },
    { title: 'New twin', href: create() },
];

export default function CreateTwin({
    projects,
    project,
    samples,
}: {
    projects: { id: string; name: string }[];
    project: string | null;
    samples: { id: string; name: string }[];
}) {
    const [source, setSource] = useState<'sample' | 'file'>('sample');

    if (projects.length === 0) {
        return (
            <>
                <Head title="New twin" />
                <div className="max-w-xl space-y-4 p-4 md:p-6">
                    <Heading
                        title="New twin"
                        description="Twins live in a project, alongside the uploads they show."
                    />
                    <Button asChild>
                        <Link href={createProject()}>
                            Create a project first
                        </Link>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="New twin" />
            <div className="max-w-xl p-4 md:p-6">
                <Heading
                    title="New twin"
                    description="Start from a sample site or upload a twin definition (JSON)."
                />
                <Form {...TwinController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="project">Project</Label>
                                <select
                                    id="project"
                                    name="project"
                                    defaultValue={project ?? projects[0]?.id}
                                    className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                >
                                    {projects.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.project} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" name="name" required />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <Input id="description" name="description" />
                                <InputError message={errors.description} />
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Start from
                                </legend>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        checked={source === 'sample'}
                                        onChange={() => setSource('sample')}
                                    />
                                    A sample site
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        checked={source === 'file'}
                                        onChange={() => setSource('file')}
                                    />
                                    A definition file
                                </label>
                            </fieldset>

                            {source === 'sample' ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="sample">Sample</Label>
                                    <select
                                        id="sample"
                                        name="sample"
                                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                    >
                                        {samples.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.sample} />
                                </div>
                            ) : (
                                <div className="grid gap-2">
                                    <Label htmlFor="definition">
                                        Definition (.json)
                                    </Label>
                                    <Input
                                        id="definition"
                                        name="definition"
                                        type="file"
                                        accept="application/json,.json"
                                        required
                                    />
                                    <InputError message={errors.definition} />
                                </div>
                            )}

                            <Button type="submit" disabled={processing}>
                                Create twin
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateTwin.layout = [AppLayout, { breadcrumbs }];
