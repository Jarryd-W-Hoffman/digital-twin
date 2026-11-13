import { Head, router, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { destroy, index, store, update } from '@/routes/organisation/members';
import type { BreadcrumbItem } from '@/types';
import type { OrganisationRole } from '@/types/auth';

type Member = {
    id: string;
    name: string;
    email: string;
    role: OrganisationRole;
    isYou: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Members', href: index() }];

const selectClass = 'h-9 rounded-md border bg-transparent px-2 text-sm';

export default function Members({
    currentOrganisation: organisation,
    members,
    roles,
    canManage,
}: {
    currentOrganisation: { id: string; name: string };
    members: Member[];
    roles: { value: OrganisationRole; label: string }[];
    canManage: boolean;
}) {
    const form = useForm<{ email: string; role: OrganisationRole }>({
        email: '',
        role: 'viewer',
    });

    return (
        <>
            <Head title="Members" />
            <div className="flex max-w-3xl flex-col gap-8 p-4 md:p-6">
                <Heading
                    title={organisation.name}
                    description="Owners and admins edit twins and manage members. Operators send commands. Viewers watch."
                />

                <table className="w-full text-sm">
                    <thead className="text-left text-muted-foreground">
                        <tr>
                            <th className="py-2 font-medium">Name</th>
                            <th className="py-2 font-medium">Role</th>
                            {canManage && <th className="sr-only">Actions</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((m) => (
                            <tr key={m.id} className="border-t">
                                <td className="py-3">
                                    <div className="font-medium">
                                        {m.name}
                                        {m.isYou && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (you)
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {m.email}
                                    </div>
                                </td>
                                <td className="py-3">
                                    {canManage ? (
                                        <select
                                            aria-label={`Role for ${m.name}`}
                                            className={selectClass}
                                            value={m.role}
                                            onChange={(e) =>
                                                router.patch(
                                                    update.url(m.id),
                                                    { role: e.target.value },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {roles.map((r) => (
                                                <option
                                                    key={r.value}
                                                    value={r.value}
                                                >
                                                    {r.label}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <span className="capitalize">
                                            {m.role}
                                        </span>
                                    )}
                                </td>
                                {canManage && (
                                    <td className="py-3 text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                confirm(
                                                    `Remove ${m.name} from ${organisation.name}?`,
                                                ) &&
                                                router.delete(
                                                    destroy.url(m.id),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
                <InputError message={form.errors.role} />

                {canManage && (
                    <form
                        className="grid gap-4 rounded-xl border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(store.url(), {
                                preserveScroll: true,
                                onSuccess: () => form.reset('email'),
                            });
                        }}
                    >
                        <Heading
                            variant="small"
                            title="Add a member"
                            description="They need a Meridian account already."
                        />
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="member-email">Email</Label>
                                <Input
                                    id="member-email"
                                    type="email"
                                    required
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="member-role">Role</Label>
                                <select
                                    id="member-role"
                                    className={selectClass}
                                    value={form.data.role}
                                    onChange={(e) =>
                                        form.setData(
                                            'role',
                                            e.target.value as OrganisationRole,
                                        )
                                    }
                                >
                                    {roles.map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Add
                            </Button>
                        </div>
                        <InputError message={form.errors.email} />
                    </form>
                )}
            </div>
        </>
    );
}

Members.layout = [AppLayout, { breadcrumbs }];
