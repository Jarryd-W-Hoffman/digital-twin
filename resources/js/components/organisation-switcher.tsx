import { router, useForm, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { store } from '@/routes/organisations';
import { update as makeCurrent } from '@/routes/organisations/current';

export function OrganisationSwitcher() {
    const { organisation } = usePage().props;
    const [creating, setCreating] = useState(false);
    const form = useForm({ name: '' });

    if (!organisation?.current) {
        return null;
    }

    const current = organisation.current;

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu>
                <SidebarMenuItem>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <SidebarMenuButton
                                size="lg"
                                data-test="organisation-switcher"
                            >
                                <Building2 />
                                <span className="grid flex-1 text-left leading-tight">
                                    <span className="truncate font-medium">
                                        {current.name}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground capitalize">
                                        {current.role}
                                    </span>
                                </span>
                                <ChevronsUpDown className="ml-auto size-4" />
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="w-60" align="start">
                            {organisation.all.map((o) => (
                                <DropdownMenuItem
                                    key={o.id}
                                    onSelect={() =>
                                        o.id !== current.id &&
                                        router.put(makeCurrent.url(o.id))
                                    }
                                >
                                    <span className="flex-1 truncate">
                                        {o.name}
                                    </span>
                                    {o.id === current.id && (
                                        <Check className="size-4" />
                                    )}
                                </DropdownMenuItem>
                            ))}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={() => setCreating(true)}
                            >
                                <Plus className="size-4" />
                                New organisation
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(store.url(), {
                                onSuccess: () => {
                                    setCreating(false);
                                    form.reset();
                                },
                            });
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>New organisation</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="organisation-name">Name</Label>
                            <Input
                                id="organisation-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                required
                                autoFocus
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>
                                Create
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </SidebarGroup>
    );
}
