import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';
import { usePage } from '@inertiajs/react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const demoMode = usePage<{ demoMode: boolean }>().props.demoMode;
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {demoMode && (
                    <p
                        role="status"
                        className="border-b bg-secondary px-6 py-3 text-sm font-medium text-secondary-foreground"
                    >
                        DEMO DATA — fictional shop transactions for testing. Do
                        not send real money using demo payment details.
                    </p>
                )}
                {children}
            </AppContent>
        </AppShell>
    );
}
