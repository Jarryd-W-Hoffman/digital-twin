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
    const [source, setSource] = useState<'blank' | 'sample' | 'file'>('blank');
    // The browser's own offset, a good guess for a site nearby.
    const [utcOffset] = useState(() => -new Date().getTimezoneOffset() / 60);

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
                    description="Start from an empty site on the map, a sample, or a twin definition (JSON)."
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
                                {(
                                    [
                                        [
                                            'blank',
                                            'An empty site at a location',
                                        ],
                                        ['sample', 'A sample site'],
                                        ['file', 'A definition file'],
                                    ] as const
                                ).map(([value, label]) => (
                                    <label
                                        key={value}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="radio"
                                            name="source"
                                            value={value}
                                            checked={source === value}
                                            onChange={() => setSource(value)}
                                        />
                                        {label}
                                    </label>
                                ))}
                                <InputError message={errors.source} />
                            </fieldset>

                            {source === 'blank' && (
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="lat">Latitude</Label>
                                        <Input
                                            id="lat"
                                            name="lat"
                                            type="number"
                                            step="any"
                                            min={-90}
                                            max={90}
                                            required
                                        />
                                        <InputError message={errors.lat} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="lon">Longitude</Label>
                                        <Input
                                            id="lon"
                                            name="lon"
                                            type="number"
                                            step="any"
                                            min={-180}
                                            max={180}
                                            required
                                        />
                                        <InputError message={errors.lon} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="utc_offset">
                                            UTC offset (hours)
                                        </Label>
                                        <Input
                                            id="utc_offset"
                                            name="utc_offset"
                                            type="number"
                                            step="0.25"
                                            min={-12}
                                            max={14}
                                            defaultValue={utcOffset}
                                        />
                                        <InputError
                                            message={errors.utc_offset}
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground sm:col-span-3">
                                        The site gets weather, a grid connection
                                        and a main board. Add models, scans and
                                        equipment from its Layout panel.
                                    </p>
                                </div>
                            )}

                            {source === 'sample' && (
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
                            )}

                            {source === 'file' && (
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
