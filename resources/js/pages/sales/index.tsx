import { Head, Link, usePage } from '@inertiajs/react';
import ListPagination from '@/components/list-pagination';
import TransactionFilterForm from '@/components/transaction-filters';
import { Button } from '@/components/ui/button';
import { kes } from '@/lib/catalogue';
import type { Auth } from '@/types';
import type { Paginated } from '@/types/catalogue';
import type { SaleSummary, TransactionFilters } from '@/types/sales';

export default function Sales({
    sales,
    filters,
    employees,
}: {
    sales: Paginated<SaleSummary>;
    filters: TransactionFilters;
    employees: { id: number; name: string }[];
}) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Sales" />
            <div className="flex flex-wrap justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {owner ? 'All sales' : 'My sales'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Posted sales and printable receipts.
                    </p>
                </div>
                <Button asChild>
                    <Link href="/sales/create">New sale</Link>
                </Button>
            </div>
            <TransactionFilterForm
                action="/sales"
                filters={filters}
                employees={employees}
            />
            {sales.data.length === 0 && (
                <p className="rounded-xl border border-dashed p-6">
                    No sales match this selection.
                </p>
            )}
            <div className="space-y-3">
                {sales.data.map((sale) => (
                    <Link
                        key={sale.id}
                        href={`/sales/${sale.id}`}
                        className="flex flex-wrap justify-between gap-3 rounded-xl border bg-card p-5 hover:bg-muted"
                    >
                        <div>
                            <h2 className="font-semibold">{sale.reference}</h2>
                            <p className="text-sm">
                                {sale.employee_name} ·{' '}
                                {new Date(sale.sold_at).toLocaleString(
                                    'en-KE',
                                    { timeZone: 'Africa/Nairobi' },
                                )}{' '}
                                Nairobi
                            </p>
                        </div>
                        <p className="font-semibold">
                            {kes(sale.total)} ·{' '}
                            {sale.settlement.state === 'voided'
                                ? 'Voided'
                                : sale.settlement.state === 'paid'
                                  ? 'Paid'
                                  : sale.settlement.state === 'disputed'
                                    ? 'Payment disputed'
                                    : sale.settlement.state === 'credit_due'
                                      ? 'Credit due'
                                      : 'Pending verification'}
                        </p>
                    </Link>
                ))}
            </div>
            <ListPagination {...sales} />
        </div>
    );
}

Sales.layout = { breadcrumbs: [{ title: 'Sales', href: '/sales' }] };
