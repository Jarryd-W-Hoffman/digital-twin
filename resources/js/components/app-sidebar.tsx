import { Link } from '@inertiajs/react';
import { BookOpen, FolderKanban, Globe, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { OrganisationSwitcher } from '@/components/organisation-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, demo } from '@/routes';
import { index as members } from '@/routes/organisation/members';
import { index as projects } from '@/routes/projects';
import { index as twins } from '@/routes/twins';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Projects',
        href: projects(),
        icon: FolderKanban,
    },
    {
        title: 'Twins',
        href: twins(),
        icon: Globe,
    },
    {
        title: 'Members',
        href: members(),
        icon: Users,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Sample twins',
        href: demo(),
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <OrganisationSwitcher />
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
