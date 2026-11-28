import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import ListPagination from '@/components/list-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { Paginated } from '@/types/catalogue';
import { batchLabels } from '@/types/inventory';
import type { InventoryBatch } from '@/types/inventory';

type Summary = {
    opening_as_of: string | null;
    opening_completed_at: string | null;
    stock_listings: number;
    missing_counts: number;
    debt_total: string;
    known_stock_value: string;
    unknown_cost_listings: number;
};

export default function Inventory({
    batches,
    summary,
    today,
    status,
}: {
    batches: Paginated<InventoryBatch>;
    summary: Summary;
    today: string;
    status?: string;
}) {
    const completed = summary.opening_completed_at !== null;
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Stock and opening balances" />
            <div className="flex flex-wrap justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Stock and opening balances
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Draft, review, then post. Every posting keeps its
                        original record.
                    </p>
                </div>
                <Link
                    href="/owner/inventory/ledger"
                    className="text-sm underline"
                >
                    View stock movements
                </Link>
            </div>
            {status && (
                <p role="status" className="rounded border p-4">
                    {status}
                </p>
            )}
            <div className="grid gap-4 sm:grid-cols-3">
                <div className="rounded-xl border bg-card p-5">
                    <p className="text-sm text-muted-foreground">
                        Opening status
                    </p>
                    <p className="mt-2 font-semibold">
                        {completed ? 'Signed off' : 'Awaiting owner sign-off'}
                    </p>
                    <p className="mt-1 text-sm">
                        {summary.stock_listings} listings counted ·{' '}
                        {summary.missing_counts} active listings without a count
                    </p>
                </div>
                <div className="rounded-xl border bg-card p-5">
                    <p className="text-sm text-muted-foreground">
                        Opening customer debt
                    </p>
                    <p className="mt-2 font-semibold">
                        {kes(summary.debt_total)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Excluded from sales revenue
                    </p>
                </div>
                <div className="rounded-xl border bg-card p-5">
                    <p className="text-sm text-muted-foreground">
                        Known inventory value
                    </p>
                    <p className="mt-2 font-semibold">
                        {kes(summary.known_stock_value)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {summary.unknown_cost_listings > 0
                            ? `Incomplete: ${summary.unknown_cost_listings} listings have unknown costs`
                            : 'Based on posted stock costs'}
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap gap-3">
                {completed ? (
                    <Button asChild>
                        <Link href="/owner/inventory/create?kind=stock_receipt">
                            Receive stock
                        </Link>
                    </Button>
                ) : (
                    <>
                        <Button asChild>
                            <Link href="/owner/inventory/create?kind=opening_stock">
                                Enter opening stock
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/owner/inventory/create?kind=opening_debt">
                                Enter opening debts
                            </Link>
                        </Button>
                    </>
                )}
            </div>
            <section className="space-y-4">
                <h2 className="font-semibold">Batches and deliveries</h2>
                {batches.data.length === 0 && (
                    <p className="rounded-xl border border-dashed p-6">
                        No batches yet. Start with the physically counted
                        opening stock.
                    </p>
                )}
                <div className="space-y-3">
                    {batches.data.map((batch) => (
                        <Link
                            key={batch.id}
                            href={`/owner/inventory/${batch.id}`}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-4 hover:bg-muted"
                        >
                            <div>
                                <p className="font-semibold">
                                    {batchLabels[batch.kind]} · {batch.status}
                                </p>
                                <p className="text-xs break-all text-muted-foreground">
                                    {batch.reference}
                                </p>
                                <p className="text-sm">
                                    As of {batch.effective_on}
                                </p>
                            </div>
                            <span className="text-sm">
                                {batch.total === null
                                    ? 'Cost incomplete'
                                    : kes(batch.total)}
                            </span>
                        </Link>
                    ))}
                </div>
                <ListPagination {...batches} />
            </section>
            {!completed && (
                <section className="max-w-2xl space-y-4 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Sign off opening balances</h2>
                    <p className="text-sm text-muted-foreground">
                        Compare the posted batches with the paper records. Every
                        active product needs a count, including zero. Post or
                        cancel all drafts before signing off.
                    </p>
                    <Form
                        action="/owner/inventory/complete-opening"
                        method="post"
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <InputError message={errors.opening} />
                                <div className="space-y-2">
                                    <Label htmlFor="effective_on">
                                        Opening as-of date
                                    </Label>
                                    <Input
                                        id="effective_on"
                                        name="effective_on"
                                        type="date"
                                        required
                                        defaultValue={
                                            summary.opening_as_of ?? today
                                        }
                                        max={today}
                                    />
                                    <InputError message={errors.effective_on} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="expected_debt_total">
                                        Debt total from paper records (KES)
                                    </Label>
                                    <Input
                                        id="expected_debt_total"
                                        name="expected_debt_total"
                                        required
                                        inputMode="decimal"
                                    />
                                    <InputError
                                        message={errors.expected_debt_total}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="reason">
                                        Reconciliation and sign-off note
                                    </Label>
                                    <Input
                                        id="reason"
                                        name="reason"
                                        required
                                        maxLength={1000}
                                    />
                                    <InputError message={errors.reason} />
                                </div>
                                <label className="flex items-start gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        name="confirm"
                                        value="1"
                                        required
                                        className="mt-1"
                                    />
                                    I checked stock, known and missing costs,
                                    and all outstanding paper debts. Zero debt
                                    means no paper debts are owed.
                                </label>
                                <InputError message={errors.confirm} />
                                <Button disabled={processing}>
                                    Approve opening balances
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            )}
        </div>
    );
}

Inventory.layout = {
    breadcrumbs: [
        { title: 'Stock and opening balances', href: '/owner/inventory' },
    ],
};
