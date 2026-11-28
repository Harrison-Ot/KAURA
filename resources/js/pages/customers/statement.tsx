import { minorMoney } from '@/lib/money';
import { Head, Link } from '@inertiajs/react';
import { kes } from '@/lib/catalogue';
import CustomerCreditPayout from '@/components/customer-credit-payout';

type Receivable = {
    id: number;
    origin: string;
    original_amount: string;
    remaining_amount: string;
    originated_on: string;
    due_on: string;
    source_note: string;
    sale_id: number | null;
    payment_id: number | null;
    payment_status: string | null;
    void_canceled_amount: string | null;
};

export default function Statement({
    customer,
    receivables,
    allocations,
    unconfirmedInstalments,
    credits,
    adjustments,
}: {
    adjustments: {
        id: number;
        kind: string;
        amount_minor: string;
        debt_amount_minor: string;
        cash_amount_minor: string;
        reason: string;
        business_date: string;
    }[];
    customer: { id: number; name: string; contact: string };
    receivables: Receivable[];
    credits: {
        submission_key: string;
        payout_outflow_id: number | null;
        id: number;
        sale_id: number;
        business_date: string;
        original: string;
        remaining: string;
    }[];
    unconfirmedInstalments: {
        id: number;
        reference: string;
        status: string;
        amount: string;
        business_date: string;
        mpesa_code: string;
    }[];
    allocations: {
        id: number;
        payment_id: number;
        reference: string;
        amount: string;
        handled_by_name: string;
    }[];
}) {
    return (
        <div className="max-w-3xl space-y-6 p-6">
            <Head title="Customer statement" />
            <Link
                href={`/customers/${customer.id}/edit`}
                className="text-sm underline"
            >
                Back to customer
            </Link>
            <div>
                <h1 className="text-2xl font-semibold">{customer.name}</h1>
                <p>{customer.contact}</p>
                <p className="mt-2 text-sm text-muted-foreground">
                    Opening debts are excluded from sales revenue. Manual M-Pesa
                    sale balances remain outstanding until owner verification;
                    pending claims are shown separately from confirmed
                    settlements.
                </p>
            </div>
            <Link
                href={`/owner/operations/create?customer_id=${customer.id}`}
                className="inline-flex rounded-lg border px-4 py-2 text-sm font-semibold text-primary"
            >
                Apply retained credit / write off debt
            </Link>
            {adjustments.length > 0 && (
                <section className="space-y-3 rounded-xl border p-5">
                    <h2 className="font-semibold">
                        Returns & balance adjustments
                    </h2>
                    {adjustments.map((adjustment) => (
                        <div key={adjustment.id} className="text-sm">
                            <p className="font-medium">
                                {adjustment.business_date} ?{' '}
                                {adjustment.kind.replaceAll('_', ' ')} ?{' '}
                                {minorMoney(adjustment.amount_minor)}
                            </p>
                            <p>
                                Debt reduction:{' '}
                                {minorMoney(adjustment.debt_amount_minor)} ?
                                Cash refunded:{' '}
                                {minorMoney(adjustment.cash_amount_minor)}
                            </p>
                            <p className="text-muted-foreground">
                                {adjustment.reason}
                            </p>
                        </div>
                    ))}
                </section>
            )}
            {credits.length > 0 && (
                <section className="space-y-3 rounded-xl border bg-secondary p-5 text-secondary-foreground">
                    <h2 className="font-semibold">Retained customer credit</h2>
                    <p className="text-sm">
                        These amounts are owed to the customer and are separate
                        from their debts. Record a completed payout below. The
                        owner can apply retained credit to the oldest debt
                        through the linked review action.
                    </p>
                    {credits.map((credit) => (
                        <div key={credit.id} className="space-y-3">
                            <p>
                                <Link
                                    href={`/sales/${credit.sale_id}`}
                                    className="underline"
                                >
                                    Voided sale
                                </Link>{' '}
                                · {credit.business_date} · Original{' '}
                                {kes(credit.original)} · Remaining{' '}
                                {kes(credit.remaining)}
                            </p>
                            {credit.remaining !== '0.00' && (
                                <CustomerCreditPayout
                                    id={credit.id}
                                    amount={credit.remaining}
                                    submissionKey={credit.submission_key}
                                />
                            )}
                            {credit.payout_outflow_id && (
                                <Link
                                    href={`/owner/outflows/${credit.payout_outflow_id}`}
                                    className="text-sm underline"
                                >
                                    View completed credit payout
                                </Link>
                            )}
                        </div>
                    ))}
                </section>
            )}
            {unconfirmedInstalments.length > 0 && (
                <section className="space-y-3 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Unconfirmed instalments</h2>
                    <p className="text-sm">
                        These claims have not reduced the outstanding balance.
                        Pending claims reserve collection capacity until the
                        owner decides.
                    </p>
                    {unconfirmedInstalments.map((payment) => (
                        <p key={payment.id}>
                            <Link
                                href={`/payments/${payment.id}`}
                                className="underline"
                            >
                                {payment.reference}
                            </Link>{' '}
                            · {kes(payment.amount)} · {payment.status} ·{' '}
                            {payment.business_date} · {payment.mpesa_code}
                        </p>
                    ))}
                </section>
            )}
            {receivables.length === 0 && (
                <p className="rounded-xl border bg-card p-6">
                    No receivables have been posted for this customer.
                </p>
            )}
            {receivables.map((receivable) => (
                <article
                    key={receivable.id}
                    className="space-y-2 rounded-xl border bg-card p-5"
                >
                    <h2 className="font-semibold">
                        {receivable.origin === 'opening_balance'
                            ? 'Opening balance'
                            : receivable.origin === 'credit_sale'
                              ? 'Approved credit sale'
                              : 'M-Pesa verification balance'}{' '}
                        · {receivable.originated_on}
                    </h2>
                    <p>Original amount: {kes(receivable.original_amount)}</p>
                    <p>Outstanding: {kes(receivable.remaining_amount)}</p>
                    {receivable.void_canceled_amount !== null && (
                        <p className="rounded border bg-secondary p-3 text-sm text-secondary-foreground">
                            Sale voided. Remaining debt canceled:{' '}
                            {kes(receivable.void_canceled_amount)}. Original
                            allocations remain recorded; no refund is confirmed
                            here.
                        </p>
                    )}
                    {receivable.origin !== 'payment_verification' ? (
                        <p>Promised due date: {receivable.due_on}</p>
                    ) : (
                        <p>Payment status: {receivable.payment_status}</p>
                    )}
                    <p className="text-sm wrap-anywhere">
                        Source: {receivable.source_note}
                    </p>
                    {receivable.sale_id && (
                        <Link
                            href={`/sales/${receivable.sale_id}`}
                            className="text-sm underline"
                        >
                            View sale
                        </Link>
                    )}
                    {receivable.payment_id && (
                        <Link
                            href={`/payments/${receivable.payment_id}`}
                            className="ml-4 text-sm underline"
                        >
                            View payment
                        </Link>
                    )}
                </article>
            ))}
            {allocations.length > 0 && (
                <section className="space-y-3">
                    <h2 className="font-semibold">Confirmed allocations</h2>
                    {allocations.map((allocation) => (
                        <article
                            key={allocation.id}
                            className="rounded-xl border bg-card p-4"
                        >
                            <p>
                                {kes(allocation.amount)} · Recorded by{' '}
                                {allocation.handled_by_name}
                            </p>
                            <Link
                                href={`/payments/${allocation.payment_id}`}
                                className="text-sm underline"
                            >
                                {allocation.reference}
                            </Link>
                        </article>
                    ))}
                </section>
            )}
        </div>
    );
}

Statement.layout = {
    breadcrumbs: [
        { title: 'Customers', href: '/customers' },
        { title: 'Customer statement', href: '#' },
    ],
};
