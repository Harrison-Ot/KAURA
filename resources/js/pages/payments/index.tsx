import { Head, Link, usePage } from '@inertiajs/react';
import ListPagination from '@/components/list-pagination';
import TransactionFilterForm from '@/components/transaction-filters';
import { kes } from '@/lib/catalogue';
import type { Auth } from '@/types';
import type { Paginated } from '@/types/catalogue';
import type { TransactionFilters } from '@/types/sales';

type Payment = {
    id: number;
    reference: string;
    method: string;
    status: string;
    amount: string;
    business_date: string;
    sale_id: number | null;
    sale_reference: string | null;
    handled_by_name: string;
    can_view_sale: boolean;
    mpesa_code: string | null;
    receiving_number: string | null;
};

export default function Payments({
    payments,
    filters,
    employees,
    totals,
}: {
    payments: Paginated<Payment>;
    filters: TransactionFilters;
    employees: { id: number; name: string }[];
    totals: {
        confirmed_total: string;
        cash_confirmed: string;
        mpesa_recorded: string;
        mpesa_verified: string;
        mpesa_pending: string;
        mpesa_disputed: string;
    };
}) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Collections" />
            <Link href="/payments/collect" className="text-sm underline">
                Collect an instalment
            </Link>
            <h1 className="text-2xl font-semibold">
                {owner ? 'All collections' : 'My collections'}
            </h1>
            <p className="text-sm text-muted-foreground">
                Cash collections show the amount retained after change.
            </p>
            <TransactionFilterForm
                action="/payments"
                filters={filters}
                employees={employees}
            />
            <p className="text-xs text-muted-foreground">
                Totals cover all statuses for the selected entry dates and
                employee, using current verification status.
            </p>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {(
                    [
                        ['confirmed_total', 'Confirmed collections'],
                        ['cash_confirmed', 'Confirmed cash'],
                        ['mpesa_recorded', 'Recorded M-Pesa'],
                        ['mpesa_verified', 'Verified M-Pesa'],
                        ['mpesa_pending', 'Pending M-Pesa'],
                        ['mpesa_disputed', 'Disputed M-Pesa'],
                    ] as const
                ).map(([field, label]) => (
                    <div key={field} className="rounded-xl border bg-card p-4">
                        <p className="text-sm text-muted-foreground">{label}</p>
                        <p className="mt-1 font-semibold">
                            {kes(totals[field])}
                        </p>
                    </div>
                ))}
            </div>
            {owner && (
                <Link
                    href="/payments?status=pending"
                    className="inline-block text-sm font-medium underline"
                >
                    Open pending verification queue
                </Link>
            )}
            {payments.data.length === 0 && (
                <p className="rounded-xl border border-dashed p-6">
                    No collections match this selection.
                </p>
            )}
            <div className="space-y-3">
                {payments.data.map((payment) => (
                    <article
                        key={payment.id}
                        className="space-y-2 rounded-xl border bg-card p-5"
                    >
                        <h2 className="font-semibold">
                            {kes(payment.amount)} ·{' '}
                            {payment.method === 'cash'
                                ? 'Cash'
                                : 'Manual M-Pesa'}
                        </h2>
                        <p className="text-sm">
                            {payment.business_date} · {payment.handled_by_name}{' '}
                            ·{' '}
                            {payment.status === 'verified'
                                ? 'Confirmed'
                                : payment.status}
                        </p>
                        <p className="text-xs break-all text-muted-foreground">
                            {payment.reference}
                        </p>
                        {payment.mpesa_code && (
                            <p className="text-sm">
                                {payment.mpesa_code} · Receiving number{' '}
                                {payment.receiving_number}
                            </p>
                        )}
                        <Link
                            href={`/payments/${payment.id}`}
                            className="inline-block text-sm font-medium underline"
                        >
                            {owner && payment.status === 'pending'
                                ? 'Review and decide payment'
                                : 'View payment details'}
                        </Link>
                        {payment.can_view_sale && payment.sale_id ? (
                            <Link
                                className="text-sm underline"
                                href={`/sales/${payment.sale_id}`}
                            >
                                {payment.sale_reference}
                            </Link>
                        ) : (
                            payment.sale_reference && (
                                <p className="text-sm">
                                    Sale: {payment.sale_reference}
                                </p>
                            )
                        )}
                    </article>
                ))}
            </div>
            <ListPagination {...payments} />
        </div>
    );
}

Payments.layout = {
    breadcrumbs: [{ title: 'Collections', href: '/payments' }],
};
