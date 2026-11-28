import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, History } from 'lucide-react';
import ListPagination from '@/components/list-pagination';
import { minorMoney } from '@/lib/money';
import type { Paginated } from '@/types/catalogue';

type Operation = {
    id: number;
    kind: string;
    actor_name: string;
    reason: string;
    business_date: string;
    amount_minor: string;
    cash_amount_minor: string;
    debt_amount_minor: string;
    sale_id: number | null;
    customer_id: number | null;
    cash_outflow_id: number | null;
};
export default function Operations({
    operations,
}: {
    operations: Paginated<Operation>;
}) {
    const status = usePage().props.status as string | undefined;
    return (
        <div className="space-y-7 p-4 sm:p-8">
            <Head title="Returns & corrections" />
            <header className="flex items-center gap-4">
                <span className="rounded-xl bg-primary/10 p-3 text-primary">
                    <History />
                </span>
                <div>
                    <p className="text-xs tracking-widest text-muted-foreground">
                        SHOP CONTROL
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Returns & corrections
                    </h1>
                </div>
            </header>
            <p className="max-w-3xl text-sm leading-6 text-muted-foreground">
                Review a sale to return items, a customer to write off debt or
                apply retained credit, or a payment to record money returned.
                Every change preserves the original record.
            </p>
            {status && (
                <p role="status" className="rounded-xl border bg-secondary p-4">
                    {status}
                </p>
            )}
            <div className="grid gap-4 md:grid-cols-3">
                {[
                    ['sale_id', 'Return items', 'Sale ID'],
                    ['customer_id', 'Adjust customer debt', 'Customer ID'],
                    ['outflow_id', 'Reverse money out', 'Money-out ID'],
                ].map(([field, title, label]) => (
                    <Form
                        key={field}
                        action="/owner/operations/create"
                        method="get"
                        className="space-y-4 rounded-2xl border bg-card p-5"
                    >
                        <h2 className="font-semibold">{title}</h2>
                        <label className="grid gap-2 text-sm">
                            {label}
                            <input
                                type="number"
                                min="1"
                                required
                                name={field}
                                className="rounded-lg border bg-background p-2.5"
                            />
                        </label>
                        <button className="flex items-center gap-2 text-sm font-semibold text-primary">
                            Review record
                            <ArrowUpRight size={16} />
                        </button>
                    </Form>
                ))}
            </div>
            <section className="overflow-hidden rounded-2xl border bg-card">
                <div className="border-b p-5">
                    <h2 className="font-semibold">
                        Immutable operation history
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Positive cash adjustment means money paid out; negative
                        means money returned.
                    </p>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                {[
                                    'Date / operation',
                                    'Amount reversed',
                                    'Cash adjustment',
                                    'Debt reduction',
                                    'Reason / actor',
                                    'Source',
                                ].map((label) => (
                                    <th key={label} className="p-4 font-medium">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {operations.data.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="p-4 whitespace-nowrap">
                                        <p>{row.business_date}</p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            #{row.id} ·{' '}
                                            {row.kind.replaceAll('_', ' ')}
                                        </p>
                                    </td>
                                    <td className="p-4 whitespace-nowrap">
                                        {minorMoney(row.amount_minor)}
                                    </td>
                                    <td className="p-4 whitespace-nowrap">
                                        {minorMoney(row.cash_amount_minor)}
                                    </td>
                                    <td className="p-4 whitespace-nowrap">
                                        {minorMoney(row.debt_amount_minor)}
                                    </td>
                                    <td className="max-w-xs p-4">
                                        <p className="wrap-anywhere">
                                            {row.reason}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {row.actor_name}
                                        </p>
                                    </td>
                                    <td className="p-4">
                                        {row.sale_id ? (
                                            <Link
                                                className="underline"
                                                href={`/sales/${row.sale_id}`}
                                            >
                                                Sale #{row.sale_id}
                                            </Link>
                                        ) : row.customer_id ? (
                                            <Link
                                                className="underline"
                                                href={`/owner/customers/${row.customer_id}/statement`}
                                            >
                                                Customer #{row.customer_id}
                                            </Link>
                                        ) : (
                                            <Link
                                                className="underline"
                                                href={`/owner/outflows/${row.cash_outflow_id}`}
                                            >
                                                Payment #{row.cash_outflow_id}
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {operations.data.length === 0 && (
                        <p className="p-8 text-center text-muted-foreground">
                            No returns or corrections posted yet.
                        </p>
                    )}
                </div>
            </section>
            <ListPagination {...operations} />
        </div>
    );
}
