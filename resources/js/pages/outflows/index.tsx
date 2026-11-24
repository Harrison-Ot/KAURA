import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import ListPagination from '@/components/list-pagination';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { Paginated } from '@/types/catalogue';
import { outflowLabels } from '@/types/outflows';
import type { Outflow } from '@/types/outflows';

type Filters = { from: string; to: string; kind: string; method: string };
export default function MoneyOut({
    outflows,
    totals,
    filters,
}: {
    outflows: Paginated<Outflow>;
    totals: Record<
        | 'stock_purchase'
        | 'expense'
        | 'owner_withdrawal'
        | 'refund'
        | 'cash'
        | 'mpesa',
        string
    >;
    filters: Filters;
}) {
    const query = new URLSearchParams(filters).toString();
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Money out" />
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Money out</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Keep operating costs, stock purchases and owner
                        withdrawals distinct.
                    </p>
                </div>
                <Link
                    href="/owner/outflows/create"
                    className={buttonVariants()}
                >
                    Record expense or withdrawal
                </Link>
            </div>
            <Form
                key={query}
                action="/owner/outflows"
                method="get"
                className="space-y-4 rounded-xl border bg-card p-5"
            >
                {({ errors }) => (
                    <>
                        {Object.entries(errors).map(([field, message]) => (
                            <InputError key={field} message={message} />
                        ))}
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <div className="space-y-2">
                                <Label htmlFor="out-from">
                                    From (Nairobi day)
                                </Label>
                                <Input
                                    id="out-from"
                                    name="from"
                                    type="date"
                                    defaultValue={filters.from}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="out-to">Through</Label>
                                <Input
                                    id="out-to"
                                    name="to"
                                    type="date"
                                    defaultValue={filters.to}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="out-kind">Type</Label>
                                <select
                                    id="out-kind"
                                    name="kind"
                                    defaultValue={filters.kind}
                                    className="h-9 w-full rounded border border-input bg-background px-3"
                                >
                                    <option value="all">All types</option>
                                    {Object.entries(outflowLabels).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="out-method">Method</Label>
                                <select
                                    id="out-method"
                                    name="method"
                                    defaultValue={filters.method}
                                    className="h-9 w-full rounded border border-input bg-background px-3"
                                >
                                    <option value="all">Cash and M-Pesa</option>
                                    <option value="cash">Cash</option>
                                    <option value="mpesa">M-Pesa</option>
                                </select>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-4">
                            <Button>Filter ledger</Button>
                            <Link
                                href="/owner/outflows?from=&to="
                                className="text-sm underline"
                            >
                                All dates
                            </Link>
                            <Link
                                href="/owner/outflows"
                                className="text-sm underline"
                            >
                                Today
                            </Link>
                            <a
                                href={`/owner/outflows/export?${query}`}
                                className="text-sm underline"
                            >
                                Download matching CSV
                            </a>
                        </div>
                    </>
                )}
            </Form>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {(
                    [
                        'stock_purchase',
                        'expense',
                        'owner_withdrawal',
                        'refund',
                    ] as const
                ).map((kind) => (
                    <section
                        key={kind}
                        className="rounded-xl border bg-card p-5"
                    >
                        <h2 className="text-sm text-muted-foreground">
                            {outflowLabels[kind]}
                        </h2>
                        <p className="mt-2 text-xl font-semibold">
                            {kes(totals[kind])}
                        </p>
                    </section>
                ))}
            </div>
            <section className="space-y-2 rounded-xl border bg-secondary p-5 text-secondary-foreground">
                <p>
                    Cash out: {kes(totals.cash)} · M-Pesa out:{' '}
                    {kes(totals.mpesa)}
                </p>
                <p className="text-sm">
                    Totals match the selected dates, type and method.
                    Withdrawals reduce shop funds and are excluded from
                    operating expenses. Stock purchases remain inventory costs.
                    This ledger does not calculate available cash or profit.
                </p>
            </section>
            {outflows.data.length === 0 && (
                <p className="rounded-xl border border-dashed bg-card p-6">
                    No money-out entries match this selection.
                </p>
            )}
            {outflows.data.map((entry) => (
                <article
                    key={entry.id}
                    className="space-y-2 rounded-xl border bg-card p-5"
                >
                    <div className="flex flex-wrap justify-between gap-3">
                        <Link
                            href={`/owner/outflows/${entry.id}`}
                            className="font-semibold underline"
                        >
                            {entry.reference} · {outflowLabels[entry.kind]}
                        </Link>
                        <p className="font-semibold">{kes(entry.amount)}</p>
                    </div>
                    <p className="text-sm">
                        {entry.effective_on} ·{' '}
                        {entry.payment_method === 'cash' ? 'Cash' : 'M-Pesa'} ·{' '}
                        {entry.recipient}
                        {entry.category && ` · ${entry.category}`}
                    </p>
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {entry.reason}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Recorded by {entry.actor_name}
                    </p>
                </article>
            ))}
            <ListPagination {...outflows} />
        </div>
    );
}
MoneyOut.layout = {
    breadcrumbs: [{ title: 'Money out', href: '/owner/outflows' }],
};
