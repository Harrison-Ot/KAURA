import { Head, Link, usePage } from '@inertiajs/react';
import type { Auth } from '@/types';

export default function Dashboard({
    paymentAlerts,
}: {
    paymentAlerts: { pending: number; disputed: number };
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    return (
        <div className="space-y-6 p-6">
            <Head title="Workspace" />
            {paymentAlerts.disputed > 0 && (
                <p
                    role="alert"
                    className="rounded border border-destructive p-4"
                >
                    {paymentAlerts.disputed} disputed M-Pesa entries need
                    follow-up.{' '}
                    <Link
                        href="/payments?status=disputed"
                        className="underline"
                    >
                        Review unresolved payments
                    </Link>
                </p>
            )}
            {paymentAlerts.pending > 0 && (
                <p role="status" className="rounded border p-4">
                    {paymentAlerts.pending} M-Pesa entries await owner
                    verification.{' '}
                    <Link href="/payments?status=pending" className="underline">
                        View pending entries
                    </Link>
                </p>
            )}
            <div>
                <p className="text-sm text-muted-foreground">
                    Kaura Hardware ·{' '}
                    {auth.user.role === 'owner' ? 'Owner' : 'Employee'}
                </p>
                <h1 className="mt-2 text-2xl font-semibold">
                    Welcome, {auth.user.name}
                </h1>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Link
                    href="/sales/create"
                    className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                >
                    <h2 className="font-semibold">Checkout</h2>
                    <p className="text-sm text-muted-foreground">
                        Build a cart, record cash or pending manual M-Pesa, and
                        print a receipt.
                    </p>
                </Link>
                <Link
                    href="/sales"
                    className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                >
                    <h2 className="font-semibold">Sales</h2>
                    <p className="text-sm text-muted-foreground">
                        Find posted sales and view receipts.
                    </p>
                </Link>
                <Link
                    href="/products"
                    className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                >
                    <h2 className="font-semibold">Products</h2>
                    <p className="text-sm text-muted-foreground">
                        Find listings, selling prices and stock by selling unit.
                    </p>
                </Link>
                <Link
                    href="/customers"
                    className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                >
                    <h2 className="font-semibold">Customers</h2>
                    <p className="text-sm text-muted-foreground">
                        Search customer contacts and add new customers.
                    </p>
                </Link>
            </div>
            {auth.user.role === 'owner' ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Link
                        href="/owner/reports/financial"
                        className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                    >
                        <h2 className="font-semibold">Financial report</h2>
                        <p className="text-sm text-muted-foreground">
                            Review sales, collections, expenses and account
                            activity by Nairobi business date.
                        </p>
                    </Link>
                    <Link
                        href="/owner/inventory"
                        className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                    >
                        <h2 className="font-semibold">
                            Stock and opening balances
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Review opening stock, paper debts and supplier
                            deliveries.
                        </p>
                    </Link>
                    <Link
                        href="/owner/shop-settings"
                        className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                    >
                        <h2 className="font-semibold">Shop settings</h2>
                        <p className="text-sm text-muted-foreground">
                            Set the shop identity and manual receiving number.
                        </p>
                    </Link>
                    <Link
                        href="/owner/employees"
                        className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                    >
                        <h2 className="font-semibold">Employees</h2>
                        <p className="text-sm text-muted-foreground">
                            Create accounts and manage staff access.
                        </p>
                    </Link>
                    <Link
                        href="/owner/audit"
                        className="space-y-2 rounded-xl border bg-card p-6 hover:bg-muted"
                    >
                        <h2 className="font-semibold">Audit history</h2>
                        <p className="text-sm text-muted-foreground">
                            Review account changes and access events.
                        </p>
                    </Link>
                </div>
            ) : (
                <p className="rounded-xl border bg-card p-6">
                    Use checkout for cash or manual M-Pesa sales. The owner must
                    approve opening balances first.
                </p>
            )}
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Workspace', href: '/dashboard' }],
};
