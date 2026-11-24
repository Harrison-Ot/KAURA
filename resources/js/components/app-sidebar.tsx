import { Link, usePage } from '@inertiajs/react';
import { BookOpen, LayoutGrid, Package, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'My day & cash', href: '/my-day', icon: LayoutGrid },
    { title: 'Checkout', href: '/sales/create', icon: LayoutGrid },
    { title: 'Sales', href: '/sales', icon: BookOpen },
    { title: 'Collections', href: '/payments', icon: BookOpen },
    { title: 'Sale approvals', href: '/sale-requests', icon: BookOpen },
    { title: 'Collect instalment', href: '/payments/collect', icon: Users },
    { title: 'Products', href: '/products', icon: Package },
    { title: 'Stock counts', href: '/stock-counts', icon: Package },
    { title: 'Customers', href: '/customers', icon: Users },
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const items: NavItem[] =
        auth.user.role === 'owner'
            ? [
                  ...mainNavItems,
                  {
                      title: 'Returns & corrections',
                      href: '/owner/operations',
                      icon: BookOpen,
                  },
                  {
                      title: 'Import catalogue',
                      href: '/owner/catalogue-import',
                      icon: Package,
                  },
                  {
                      title: 'Voided sale review',
                      href: '/owner/sale-voids',
                      icon: BookOpen,
                  },
                  {
                      title: 'Reports & insights',
                      href: '/owner/reports/financial',
                      icon: BookOpen,
                  },
                  {
                      title: 'Money out',
                      href: '/owner/outflows',
                      icon: BookOpen,
                  },
                  {
                      title: 'Payment verification',
                      href: '/payments?status=pending',
                      icon: BookOpen,
                  },
                  {
                      title: 'Stock and opening',
                      href: '/owner/inventory',
                      icon: Package,
                  },
                  {
                      title: 'Shop settings',
                      href: '/owner/shop-settings',
                      icon: BookOpen,
                  },
                  {
                      title: 'Employees',
                      href: '/owner/employees',
                      icon: LayoutGrid,
                  },
                  {
                      title: 'Audit history',
                      href: '/owner/audit',
                      icon: BookOpen,
                  },
              ]
            : mainNavItems;
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
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
