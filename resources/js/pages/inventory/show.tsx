import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import { batchLabels } from '@/types/inventory';
import type { InventoryBatch } from '@/types/inventory';

type Item = {
    id: number;
    label: string;
    sku: string | null;
    unit_label: string | null;
    quantity: string | null;
    unit_cost: string | null;
    amount: string | null;
    due_on: string | null;
};

export default function BatchReview({
    batch,
    items,
    outflow,
}: {
    batch: InventoryBatch;
    items: Item[];
    outflow: {
        id: number;
        kind: string;
        payment_method: string;
        payment_reference: string | null;
    } | null;
}) {
    return (
        <div className="max-w-5xl space-y-6 p-4 sm:p-6">
            <Head title={`${batchLabels[batch.kind]} review`} />
            <div>
                <Link href="/owner/inventory" className="text-sm underline">
                    Back to stock and opening balances
                </Link>
                <h1 className="mt-3 text-2xl font-semibold">
                    {batchLabels[batch.kind]} · {batch.status}
                </h1>
                <p className="mt-1 text-xs break-all text-muted-foreground">
                    {batch.reference}
                </p>
            </div>
            <section className="space-y-2 rounded-xl border bg-card p-5">
                <p>As of: {batch.effective_on}</p>
                <p className="wrap-anywhere">Source: {batch.source_note}</p>
                <p className="font-semibold">
                    {batch.total === null
                        ? 'Total incomplete — missing opening costs'
                        : `Total: ${kes(batch.total)}`}
                </p>
                {batch.supplier_name && (
                    <>
                        <p>
                            Supplier: {batch.supplier_name} · Delivery
                            reference: {batch.delivery_reference}
                        </p>
                        <p>
                            Paid: {kes(batch.amount_paid ?? '0.00')} by{' '}
                            {batch.payment_method}
                            {batch.payment_reference &&
                                ` · ${batch.payment_reference}`}
                        </p>
                    </>
                )}
                {batch.posted_at && (
                    <p>
                        Posted:{' '}
                        {new Date(batch.posted_at).toLocaleString('en-KE', {
                            timeZone: 'Africa/Nairobi',
                        })}{' '}
                        Nairobi
                    </p>
                )}
                {batch.decision_reason && (
                    <p className="text-sm wrap-anywhere">
                        Decision note: {batch.decision_reason}
                    </p>
                )}
            </section>
            <section className="space-y-3">
                <h2 className="font-semibold">Review {items.length} rows</h2>
                {items.map((item, index) => (
                    <article
                        key={item.id}
                        className="space-y-2 rounded-lg border p-4"
                    >
                        <h3 className="font-semibold">
                            {index + 1}. {item.label}
                        </h3>
                        {item.sku && (
                            <p className="text-sm">
                                {item.sku} · {item.quantity} {item.unit_label} ·
                                Unit cost:{' '}
                                {item.unit_cost === null
                                    ? 'Unknown'
                                    : kes(item.unit_cost)}
                            </p>
                        )}
                        {item.due_on && (
                            <p className="text-sm">
                                Promised due date: {item.due_on}
                            </p>
                        )}
                        <p className="text-sm">
                            {item.amount === null
                                ? 'Value incomplete — cost required for valuation'
                                : `Amount: ${kes(item.amount)}`}
                        </p>
                    </article>
                ))}
            </section>
            {outflow && (
                <p className="rounded-xl border bg-card p-4 text-sm">
                    Stock purchase outflow #{outflow.id} recorded once by{' '}
                    {outflow.payment_method}. This purchase is inventory cost;
                    it is excluded from operating expenses.
                </p>
            )}
            {batch.status === 'draft' && (
                <div className="grid gap-4 md:grid-cols-2">
                    <section className="space-y-3 rounded-xl border bg-card p-5">
                        <h2 className="font-semibold">Approve and post</h2>
                        <p className="text-sm text-muted-foreground">
                            {batch.kind === 'opening_debt'
                                ? 'Creates opening receivables, without recording sales or collections.'
                                : batch.kind === 'opening_stock'
                                  ? 'Creates the counted stock and preserves any missing-cost warnings.'
                                  : 'Increases stock and records the supplier outflow together.'}
                        </p>
                        <Form
                            action={`/owner/inventory/${batch.id}/post`}
                            method="post"
                            className="space-y-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <InputError
                                        message={
                                            errors.batch ??
                                            errors.effective_on ??
                                            errors.rows
                                        }
                                    />
                                    <Label htmlFor="post_reason">
                                        Owner review note
                                    </Label>
                                    <Input
                                        id="post_reason"
                                        name="reason"
                                        maxLength={1000}
                                        required
                                    />
                                    <InputError message={errors.reason} />
                                    <label className="flex items-start gap-3 text-sm">
                                        <input
                                            type="checkbox"
                                            name="confirm"
                                            value="1"
                                            required
                                            className="mt-1"
                                        />
                                        I checked every row against the source
                                        record and approve this posting.
                                    </label>
                                    <InputError message={errors.confirm} />
                                    <Button disabled={processing}>
                                        {processing
                                            ? 'Posting…'
                                            : 'Approve and post once'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                    <section className="space-y-3 rounded-xl border bg-card p-5">
                        <h2 className="font-semibold">Discard draft</h2>
                        <p className="text-sm text-muted-foreground">
                            Cancel incorrect drafts and prepare a new review.
                            The cancelled draft remains in history.
                        </p>
                        <Form
                            action={`/owner/inventory/${batch.id}/cancel`}
                            method="post"
                            className="space-y-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <InputError message={errors.batch} />
                                    <Label htmlFor="cancel_reason">
                                        Cancellation reason
                                    </Label>
                                    <Input
                                        id="cancel_reason"
                                        name="reason"
                                        required
                                        maxLength={1000}
                                    />
                                    <InputError message={errors.reason} />
                                    <Button
                                        disabled={processing}
                                        variant="outline"
                                    >
                                        Cancel draft
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                </div>
            )}
        </div>
    );
}

BatchReview.layout = {
    breadcrumbs: [
        { title: 'Stock and opening balances', href: '/owner/inventory' },
        { title: 'Review batch', href: '#' },
    ],
};
