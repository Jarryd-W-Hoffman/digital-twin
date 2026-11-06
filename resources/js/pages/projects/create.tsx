import { Form, Head } from '@inertiajs/react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { create, index } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: index() },
    { title: 'New project', href: create() },
];

export default function CreateProject() {
    return (
        <>
            <Head title="New project" />
            <div className="max-w-xl p-4 md:p-6">
                <Heading
                    title="New project"
                    description="A site, a client or a programme of work: its twins share one library of uploads."
                />
                <Form {...ProjectController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
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
                            <Button type="submit" disabled={processing}>
                                Create project
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateProject.layout = [AppLayout, { breadcrumbs }];
