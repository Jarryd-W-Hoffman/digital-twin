import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Boxes,
    FolderKanban,
    Globe,
    KeyRound,
    ScrollText,
    ShieldCheck,
    Users,
} from 'lucide-react';
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
import { index as apiTokens } from '@/routes/organisation/api-tokens';
import { index as audit } from '@/routes/organisation/audit';
import { index as members } from '@/routes/organisation/members';
import { show as sso } from '@/routes/organisation/sso';
import { index as types } from '@/routes/organisation/types';
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
        title: 'Types',
        href: types(),
        icon: Boxes,
    },
    {
        title: 'Members',
        href: members(),
        icon: Users,
    },
    {
        title: 'API',
        href: apiTokens(),
        icon: KeyRound,
    },
];

/** Only admins and owners can read the audit log. */
const auditNavItem: NavItem = {
    title: 'Audit log',
    href: audit(),
    icon: ScrollText,
};

/** Only owners set up single sign-on. */
const ssoNavItem: NavItem = {
    title: 'Single sign-on',
    href: sso(),
    icon: ShieldCheck,
};

const footerNavItems: NavItem[] = [
    {
        title: 'Sample twins',
        href: demo(),
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const role = usePage().props.organisation?.current?.role;
    const items = [
        ...mainNavItems,
        ...(role === 'owner' || role === 'admin' ? [auditNavItem] : []),
        ...(role === 'owner' ? [ssoNavItem] : []),
    ];

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
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
