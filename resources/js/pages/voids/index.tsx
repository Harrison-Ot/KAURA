import { Form, Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import ListPagination from '@/components/list-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { Paginated } from '@/types/catalogue';

type Void = {
    customer_id: number | null;
    submission_key: string;
    resolution: {
        id: number;
        kind: 'refund' | 'customer_credit';
        amount: string;
        recipient: string;
        reason: string;
        business_date: string;
        payment_method: string | null;
        payment_reference: string | null;
    } | null;
    id: number;
    sale_id: number;
    reference: string;
    employee_name: string;
    actor_name: string;
    reason: string;
    created_at: string;
    stock_status: string;
    stock_reason: string | null;
    funds_status: string;
    canceled_debt: string;
    confirmed_funds: string;
    pending_claims: string;
};

type Customer = { id: number; name: string; contact: string };

function ResolveFunds({
    entry,
    customers,
}: {
    entry: Void;
    customers: Customer[];
}) {
    const form = useForm({
        submission_key: entry.submission_key,
        kind: 'refund',
        payment_method: 'cash',
        payment_reference: '',
        customer_id: entry.customer_id == null ? '' : String(entry.customer_id),
        recipient: '',
        reason: '',
        confirm: false,
    });
    const refund = form.data.kind === 'refund';
    const mpesa = refund && form.data.payment_method === 'mpesa';
    return (
        <form
            className="space-y-3 rounded-xl border border-input bg-background p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    submission_key: data.submission_key,
                    kind: data.kind,
                    recipient: data.recipient,
                    reason: data.reason,
                    confirm: data.confirm,
                    ...(refund
                        ? {
                              payment_method: data.payment_method,
                              ...(mpesa
                                  ? {
                                        payment_reference:
                                            data.payment_reference,
                                    }
                                  : {}),
                          }
                        : { customer_id: data.customer_id }),
                }));
                form.post(`/owner/sale-voids/${entry.id}/resolve`);
            }}
        >
            <h3 className="font-semibold">
                Resolve confirmed funds: {kes(entry.confirmed_funds)}
            </h3>
            <p className="text-sm text-muted-foreground">
                Resolve the full confirmed amount once. Record a refund only
                after funds have actually been returned. Retained credit stays
                as a separate customer liability.
            </p>
            {Object.entries(form.errors).map(([field, message]) => (
                <InputError key={field} message={message} />
            ))}
            <Label htmlFor={`fund-kind-${entry.id}`}>Disposition</Label>
            <select
                id={`fund-kind-${entry.id}`}
                value={form.data.kind}
                onChange={(event) => form.setData('kind', event.target.value)}
                className="h-10 w-full rounded border border-input bg-card px-3"
            >
                <option value="refund">Refund already paid</option>
                <option value="customer_credit">
                    Retain named customer credit
                </option>
            </select>
            {refund ? (
                <>
                    <Label htmlFor={`fund-method-${entry.id}`}>
                        Refund method
                    </Label>
                    <select
                        id={`fund-method-${entry.id}`}
                        value={form.data.payment_method}
                        onChange={(event) => {
                            form.setData('payment_method', event.target.value);
                            form.setData('payment_reference', '');
                        }}
                        className="h-10 w-full rounded border border-input bg-card px-3"
                    >
                        <option value="cash">Cash</option>
                        <option value="mpesa">M-Pesa</option>
                    </select>
                    {mpesa && (
                        <>
                            <Label htmlFor={`fund-code-${entry.id}`}>
                                Outgoing transaction code
                            </Label>
                            <Input
                                id={`fund-code-${entry.id}`}
                                value={form.data.payment_reference}
                                onChange={(event) =>
                                    form.setData(
                                        'payment_reference',
                                        event.target.value,
                                    )
                                }
                                required
                                maxLength={30}
                            />
                        </>
                    )}
                </>
            ) : (
                <>
                    <Label htmlFor={`fund-customer-${entry.id}`}>
                        Customer retaining the credit
                    </Label>
                    <select
                        id={`fund-customer-${entry.id}`}
                        value={form.data.customer_id}
                        onChange={(event) =>
                            form.setData('customer_id', event.target.value)
                        }
                        required
                        className="h-10 w-full rounded border border-input bg-card px-3"
                    >
                        <option value="">Select customer</option>
                        {customers
                            .filter(
                                (customer) =>
                                    entry.customer_id == null ||
                                    customer.id === entry.customer_id,
                            )
                            .map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.name} · {customer.contact}
                                </option>
                            ))}
                    </select>
                    <p className="text-sm text-muted-foreground">
                        The credit remains unspent. Checkout redemption and debt
                        offsets are not yet available.
                    </p>
                </>
            )}
            <Label htmlFor={`fund-recipient-${entry.id}`}>
                Recipient / customer acknowledgement
            </Label>
            <Input
                id={`fund-recipient-${entry.id}`}
                value={form.data.recipient}
                onChange={(event) =>
                    form.setData('recipient', event.target.value)
                }
                required
                maxLength={255}
            />
            <Label htmlFor={`fund-reason-${entry.id}`}>
                Reason and supporting evidence
            </Label>
            <Input
                id={`fund-reason-${entry.id}`}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                required
                maxLength={2000}
            />
            <label className="flex items-start gap-3 text-sm">
                <input
                    type="checkbox"
                    checked={form.data.confirm}
                    onChange={(event) =>
                        form.setData('confirm', event.target.checked)
                    }
                    required
                    className="mt-1"
                />
                {refund
                    ? 'I returned the full amount to this recipient and checked the payment evidence. This records an actual payout.'
                    : 'I confirmed the customer identity and their agreement to retain the full amount as unspent credit.'}
            </label>
            <Button disabled={form.processing}>
                Record{' '}
                {refund ? 'completed refund' : 'retained customer credit'}
            </Button>
        </form>
    );
}
export default function VoidReview({
    voids,
    filter,
    customers,
}: {
    voids: Paginated<Void>;
    filter: string;
    customers: Customer[];
}) {
    return (
        <div className="max-w-4xl space-y-6 p-4 sm:p-6">
            <Head title="Voided sale review" />
            <div>
                <h1 className="text-2xl font-semibold">Voided sale review</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Account for physical goods and follow up customer funds.
                    Voids preserve payments and do not issue refunds.
                </p>
            </div>
            <div className="flex gap-4 text-sm">
                <Link href="/owner/sale-voids" className="underline">
                    Open reviews
                </Link>
                <Link href="/owner/sale-voids?status=all" className="underline">
                    All voids
                </Link>
            </div>
            <p className="text-sm text-muted-foreground">
                Showing{' '}
                {filter === 'all'
                    ? 'all records'
                    : 'pending stock or funds reviews'}
                .
            </p>
            {voids.data.length === 0 && (
                <p className="rounded-xl border border-dashed bg-card p-6">
                    No voided sales match this queue.
                </p>
            )}
            {voids.data.map((entry) => (
                <article
                    key={entry.id}
                    className="space-y-4 rounded-xl border bg-card p-5"
                >
                    <Link
                        href={`/sales/${entry.sale_id}`}
                        className="font-semibold underline"
                    >
                        {entry.reference}
                    </Link>
                    <p className="text-sm">
                        Sold by {entry.employee_name} · Voided by{' '}
                        {entry.actor_name} ·{' '}
                        {new Date(entry.created_at).toLocaleString('en-KE', {
                            timeZone: 'Africa/Nairobi',
                        })}{' '}
                        Nairobi
                    </p>
                    <p className="wrap-anywhere">{entry.reason}</p>
                    <dl className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Canceled remaining debt
                            </dt>
                            <dd>{kes(entry.canceled_debt)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Confirmed funds held
                            </dt>
                            <dd>{kes(entry.confirmed_funds)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Pending claims
                            </dt>
                            <dd>{kes(entry.pending_claims)}</dd>
                        </div>
                    </dl>
                    {entry.resolution && (
                        <section className="space-y-2 rounded border bg-secondary p-4 text-secondary-foreground">
                            <h3 className="font-semibold">
                                {entry.resolution.kind === 'refund'
                                    ? 'Refund recorded'
                                    : 'Customer credit retained'}{' '}
                                · {kes(entry.resolution.amount)}
                            </h3>
                            <p className="text-sm">
                                {entry.resolution.business_date} ·{' '}
                                {entry.resolution.recipient}{' '}
                                {entry.resolution.payment_method}{' '}
                                {entry.resolution.payment_reference}
                            </p>
                            <p className="text-sm wrap-anywhere">
                                {entry.resolution.reason}
                            </p>
                        </section>
                    )}
                    {!entry.resolution &&
                        entry.funds_status === 'needs_review' && (
                            <p
                                role="status"
                                className="rounded border bg-secondary p-4 text-sm text-secondary-foreground"
                            >
                                Customer funds need owner follow-up. Check any
                                pending M-Pesa in payment verification. Refund
                                resolution requires finished stock review and
                                decided checkout claims. Voiding alone does not
                                confirm a refund.
                            </p>
                        )}
                    {!entry.resolution &&
                        entry.stock_status !== 'pending' &&
                        entry.pending_claims === '0.00' &&
                        entry.confirmed_funds !== '0.00' && (
                            <ResolveFunds
                                key={entry.id}
                                entry={entry}
                                customers={customers}
                            />
                        )}
                    <p className="text-sm">
                        Stock: {entry.stock_status.replaceAll('_', ' ')} ·{' '}
                        {entry.stock_reason}
                    </p>
                    {entry.stock_status === 'pending' && (
                        <Form
                            action={`/owner/sale-voids/${entry.id}/stock`}
                            method="post"
                            className="space-y-3 border-t pt-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {Object.entries(errors).map(
                                        ([field, message]) => (
                                            <InputError
                                                key={field}
                                                message={message}
                                            />
                                        ),
                                    )}
                                    <Label htmlFor={`decision-${entry.id}`}>
                                        Physical goods decision
                                    </Label>
                                    <select
                                        id={`decision-${entry.id}`}
                                        name="decision"
                                        className="h-10 w-full rounded border border-input bg-background px-3"
                                    >
                                        <option value="not_restocked">
                                            Do not restock — goods unavailable
                                            or not resellable
                                        </option>
                                        <option value="restocked">
                                            Restock all original quantities —
                                            physically present and resellable
                                        </option>
                                    </select>
                                    <Label htmlFor={`reason-${entry.id}`}>
                                        Review reason and goods location
                                    </Label>
                                    <Input
                                        id={`reason-${entry.id}`}
                                        name="reason"
                                        required
                                        maxLength={2000}
                                    />
                                    <label className="flex items-start gap-3 text-sm">
                                        <input
                                            type="checkbox"
                                            name="confirm"
                                            value="1"
                                            required
                                            className="mt-1"
                                        />
                                        I physically accounted for the goods.
                                        Restocking confirms every original unit
                                        is present and resellable. Partial
                                        returns need a separate workflow.
                                    </label>
                                    <Button disabled={processing}>
                                        Save stock disposition
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </article>
            ))}
            <ListPagination {...voids} />
        </div>
    );
}
VoidReview.layout = {
    breadcrumbs: [{ title: 'Voided sale review', href: '/owner/sale-voids' }],
};
