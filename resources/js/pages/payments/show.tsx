import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { Auth } from '@/types';
import type { SaleSummary } from '@/types/sales';

type Payment = {
    id: number;
    reference: string;
    method: string;
    status: string;
    business_date: string;
    amount: string;
    mpesa_code: string | null;
    receiving_number: string | null;
    payer_reference: string | null;
    handled_by_name: string;
    purpose: string;
};
type Props = {
    customer: { name: string; contact: string } | null;
    allocationNotes:
        | { receivable_id: number; reserved_minor: number; reason: string }[]
        | null;
    allocations: {
        id: number;
        reference: string;
        amount: string;
        sale_id: number | null;
        can_view_sale: boolean;
    }[];
    payment: Payment;
    sale: {
        id: number;
        reference: string;
        settlement?: SaleSummary['settlement'];
    } | null;
    canViewSale: boolean;
    decision: {
        decision: string;
        reason: string;
        actor_name: string;
        created_at: string;
    } | null;
    status?: string;
};

export default function PaymentDetails({
    payment,
    sale,
    canViewSale,
    decision,
    status,
    allocations,
    customer,
    allocationNotes,
}: Props) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="max-w-3xl space-y-6 p-4 sm:p-6">
            <Head title="Payment details" />
            <a
                href={`/payments/${payment.id}/receipt`}
                target="_blank"
                rel="noopener noreferrer"
                className="text-sm underline"
            >
                Open printable payment receipt
            </a>
            <Link href="/payments" className="text-sm underline">
                Back to collections
            </Link>
            <div>
                <h1 className="text-2xl font-semibold">
                    {payment.method === 'cash'
                        ? 'Cash collection'
                        : 'Manual M-Pesa'}{' '}
                    ·{' '}
                    {payment.status === 'verified'
                        ? 'Confirmed'
                        : payment.status}
                </h1>
                <p className="mt-2 text-xs break-all text-muted-foreground">
                    {payment.reference}
                </p>
            </div>
            {status && (
                <p role="status" className="rounded border p-4">
                    {status}
                </p>
            )}
            <section className="space-y-3 rounded-xl border bg-card p-5">
                <p>
                    {payment.purpose === 'instalment'
                        ? 'Customer instalment'
                        : 'Checkout payment'}
                </p>
                {customer && (
                    <p>
                        Customer: {customer.name} · {customer.contact}
                    </p>
                )}
                <p className="text-xl font-semibold">{kes(payment.amount)}</p>
                <p>
                    Recorded by {payment.handled_by_name} ·{' '}
                    {payment.business_date}
                </p>
                {payment.method === 'manual_mpesa' && (
                    <>
                        <p>Transaction code: {payment.mpesa_code}</p>
                        <p>
                            Receiving number at entry:{' '}
                            <strong>{payment.receiving_number}</strong>
                        </p>
                        <p>
                            Payer reference:{' '}
                            {payment.payer_reference ?? 'Not provided'}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Reconcile against this historical number, even if
                            the shop now displays a different number.
                        </p>
                    </>
                )}
                {sale &&
                    (canViewSale ? (
                        <Link
                            className="text-sm underline"
                            href={`/sales/${sale.id}`}
                        >
                            Sale {sale.reference}
                        </Link>
                    ) : (
                        <p className="text-sm">
                            Sale reference: {sale.reference}
                        </p>
                    ))}
                {sale?.settlement && (
                    <p className="font-medium">
                        Unresolved sale balance:{' '}
                        {kes(sale.settlement.balance_due)}
                    </p>
                )}
                {sale?.settlement?.state === 'voided' && (
                    <p className="rounded border bg-secondary p-4 text-sm text-secondary-foreground">
                        This sale is voided. The payment remains in history;
                        confirmed funds require owner follow-up and no refund is
                        recorded by voiding.
                    </p>
                )}
            </section>
            {allocations.length > 0 && (
                <section className="space-y-3 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Confirmed allocations</h2>
                    {allocations.map((allocation) => (
                        <p key={allocation.id}>
                            {kes(allocation.amount)} ·{' '}
                            {allocation.can_view_sale && allocation.sale_id ? (
                                <Link
                                    href={`/sales/${allocation.sale_id}`}
                                    className="underline"
                                >
                                    {allocation.reference}
                                </Link>
                            ) : (
                                allocation.reference
                            )}
                        </p>
                    ))}
                </section>
            )}
            {allocationNotes && allocationNotes.length > 0 && (
                <section className="space-y-2 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Allocation exceptions</h2>
                    <p className="text-sm">
                        Pending checkout claims reserve their amounts. Confirmed
                        instalments allocate to the oldest remaining eligible
                        balances.
                    </p>
                    {allocationNotes.map((note) => (
                        <p key={note.receivable_id}>
                            Receivable #{note.receivable_id}: pending checkout
                            amount reserved.
                        </p>
                    ))}
                </section>
            )}
            {payment.status === 'pending' && (
                <p role="alert" className="rounded border p-4">
                    Pending verification. This amount is excluded from confirmed
                    collections and has not reduced the outstanding balance.
                </p>
            )}
            {payment.status === 'disputed' && (
                <p
                    role="alert"
                    className="rounded border border-destructive p-4"
                >
                    Disputed payment. The unpaid balance needs owner follow-up.
                </p>
            )}
            {decision && (
                <section className="space-y-2 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">
                        Owner decision: {decision.decision}
                    </h2>
                    <p className="text-sm">
                        {decision.actor_name} ·{' '}
                        {new Date(decision.created_at).toLocaleString('en-KE', {
                            timeZone: 'Africa/Nairobi',
                        })}{' '}
                        Nairobi
                    </p>
                    <p className="wrap-anywhere">{decision.reason}</p>
                </section>
            )}
            {owner &&
                payment.method === 'manual_mpesa' &&
                payment.status === 'pending' && (
                    <section className="space-y-4 rounded-xl border bg-card p-5">
                        <h2 className="font-semibold">
                            Reconcile M-Pesa entry
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Compare the receiving number's messages or statement
                            with the code and exact amount above.
                        </p>
                        <Form
                            action={`/owner/payments/${payment.id}/decision`}
                            method="post"
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="decision">
                                            Decision
                                        </Label>
                                        <select
                                            id="decision"
                                            name="decision"
                                            className="h-9 w-full rounded border bg-background px-3 text-sm"
                                        >
                                            <option value="verified">
                                                Verify — money received
                                            </option>
                                            <option value="disputed">
                                                Dispute — unresolved payment
                                            </option>
                                        </select>
                                        <InputError message={errors.decision} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="reason">
                                            Review evidence / reason
                                        </Label>
                                        <Input
                                            id="reason"
                                            name="reason"
                                            required
                                            maxLength={2000}
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
                                        I checked the code, amount and
                                        historical receiving number against the
                                        owner's records.
                                    </label>
                                    <InputError message={errors.confirm} />
                                    <Button disabled={processing}>
                                        {processing
                                            ? 'Saving decision…'
                                            : 'Save owner decision'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}
        </div>
    );
}

PaymentDetails.layout = {
    breadcrumbs: [
        { title: 'Collections', href: '/payments' },
        { title: 'Payment details', href: '#' },
    ],
};
