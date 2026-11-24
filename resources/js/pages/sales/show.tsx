import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Auth } from '@/types';
import { Button } from '@/components/ui/button';
import { kes, quantity } from '@/lib/catalogue';
import type { SaleDetails } from '@/types/sales';

export default function SaleView({ sale }: { sale: SaleDetails }) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="max-w-4xl space-y-6 p-4 sm:p-6">
            <Head title={sale.reference} />
            <div className="flex flex-wrap justify-between gap-4">
                <div>
                    <Link href="/sales" className="text-sm underline">
                        Back to sales
                    </Link>
                    <h1 className="mt-3 text-2xl font-semibold">
                        {sale.reference}
                    </h1>
                    <p className="text-sm">
                        {sale.employee_name} ·{' '}
                        {new Date(sale.sold_at).toLocaleString('en-KE', {
                            timeZone: 'Africa/Nairobi',
                        })}{' '}
                        Nairobi · {sale.status}
                    </p>
                </div>
                <Button asChild>
                    <a
                        href={`/sales/${sale.id}/receipt`}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open printable receipt
                    </a>
                </Button>
            </div>
            {sale.void && (
                <section className="space-y-3 rounded-xl border bg-secondary p-5 text-secondary-foreground">
                    <h2 className="font-semibold">
                        Voided sale — original history preserved
                    </h2>
                    <p>
                        {sale.void.actor_name} ·{' '}
                        {new Date(sale.void.created_at).toLocaleString(
                            'en-KE',
                            { timeZone: 'Africa/Nairobi' },
                        )}{' '}
                        Nairobi
                    </p>
                    <p className="wrap-anywhere">Reason: {sale.void.reason}</p>
                    <p>
                        Canceled outstanding debt:{' '}
                        {kes(sale.void.canceled_debt)}
                    </p>
                    <p>
                        Original confirmed funds:{' '}
                        {kes(sale.void.confirmed_funds)}
                    </p>
                    <p>
                        Stock: {sale.void.stock_status.replaceAll('_', ' ')}.{' '}
                        {sale.void.stock_reason}
                    </p>
                    {sale.void.resolution ? (
                        <p className="text-sm">
                            {sale.void.resolution.kind === 'refund'
                                ? 'Owner refund recorded'
                                : 'Retained customer credit recorded'}
                            : {kes(sale.void.resolution.amount)} ·{' '}
                            {sale.void.resolution.business_date}. Original
                            payments and allocations remain in history.
                        </p>
                    ) : (
                        <p className="text-sm">
                            Payments and allocations remain in history. Voiding
                            does not return funds. Any confirmed money needs
                            owner refund or retained-credit resolution.
                        </p>
                    )}
                    {owner && (
                        <Link
                            href="/owner/sale-voids"
                            className="text-sm underline"
                        >
                            Open owner void review
                        </Link>
                    )}
                </section>
            )}
            <section className="space-y-2 rounded-xl border bg-card p-5">
                <h2 className="font-semibold">
                    {sale.shop_snapshot.shop_name}
                </h2>
                <p>
                    Customer:{' '}
                    {sale.customer_snapshot?.name ?? 'Walk-in customer'}
                </p>
                <p className="text-sm text-muted-foreground">
                    Shop and item details are preserved from checkout.
                </p>
            </section>
            <section className="space-y-3">
                <h2 className="font-semibold">Items</h2>
                {sale.items.map((item) => (
                    <article
                        key={item.id}
                        className="space-y-2 rounded-xl border bg-card p-4"
                    >
                        <h3 className="font-semibold">{item.name}</h3>
                        <p className="text-sm">
                            {item.sku} ·{' '}
                            {quantity(item.quantity, item.quantity_precision)}{' '}
                            {item.unit_label} × {kes(item.unit_price)}
                        </p>
                        <p className="font-medium">{kes(item.total)}</p>
                        {'cost_total' in item && (
                            <p className="text-xs text-muted-foreground">
                                Inventory cost:{' '}
                                {item.cost_total === null
                                    ? 'Incomplete — unknown purchase costs'
                                    : kes(item.cost_total ?? '0.00')}
                            </p>
                        )}
                    </article>
                ))}
            </section>
            <section className="space-y-2 rounded-xl border bg-card p-5">
                <p className="text-xl font-semibold">
                    Sale total: {kes(sale.total)}
                </p>
                {sale.discount !== '0.00' && (
                    <p>
                        Gross {kes(sale.gross)} · Approved discount{' '}
                        {kes(sale.discount)}
                    </p>
                )}
                {sale.promised_due_on && (
                    <p>
                        Approved credit {kes(sale.approved_credit)} · Promised
                        date {sale.promised_due_on}
                    </p>
                )}
                {sale.checkout_method === 'cash' && (
                    <>
                        <p>Cash tendered: {kes(sale.cash_tendered)}</p>
                        <p>Change returned: {kes(sale.change)}</p>
                    </>
                )}
                {sale.payments.map((payment) => (
                    <div
                        key={payment.id}
                        className="space-y-1 rounded border p-3"
                    >
                        <p>
                            {payment.method === 'cash'
                                ? 'Cash retained'
                                : 'Manual M-Pesa'}
                            : {kes(payment.amount)} ·{' '}
                            {payment.status === 'verified'
                                ? 'Confirmed'
                                : payment.status}
                        </p>
                        {payment.mpesa_code && (
                            <p className="text-sm">
                                Code: {payment.mpesa_code} · Receiving number:{' '}
                                {payment.receiving_number}
                            </p>
                        )}
                        <Link
                            href={`/payments/${payment.id}`}
                            className="text-sm underline"
                        >
                            View payment and verification
                        </Link>
                    </div>
                ))}
                <p className="font-medium">
                    {sale.settlement.state === 'voided'
                        ? 'Voided — no sale balance due'
                        : sale.settlement.state === 'paid'
                          ? 'Paid in full'
                          : sale.settlement.state === 'disputed'
                            ? 'Disputed payment — follow-up required'
                            : sale.settlement.state === 'credit_due'
                              ? 'Approved credit due'
                              : 'Awaiting owner verification'}{' '}
                    · Balance due: {kes(sale.settlement.balance_due)}
                </p>
                {['pending_verification', 'disputed'].includes(
                    sale.settlement.state,
                ) && (
                    <p role="alert" className="text-sm text-destructive">
                        Pending and disputed M-Pesa entries are not confirmed
                        collections. The sale and stock deduction remain
                        recorded.
                    </p>
                )}
                {'cost_total' in sale && (
                    <p className="text-sm text-muted-foreground">
                        Recorded cost of goods:{' '}
                        {sale.cost_total === null
                            ? 'Incomplete'
                            : kes(sale.cost_total ?? '0.00')}
                    </p>
                )}
            </section>
            {sale.status === 'posted' && (
                <section className="space-y-3 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Void this completed sale</h2>
                    <p className="text-sm text-muted-foreground">
                        Cancel the sale and its remaining debt with a reason.
                        Payments stay recorded. Stock stays unavailable until
                        the owner physically accounts for all goods and approves
                        their disposition.
                    </p>
                    <Form
                        action={`/sales/${sale.id}/void`}
                        method="post"
                        className="space-y-3"
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
                                <Label htmlFor="void-reason">
                                    Reason for void
                                </Label>
                                <Input
                                    id="void-reason"
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
                                    I reviewed this sale. Voiding cancels its
                                    remaining debt and does not return funds or
                                    automatically restock goods.
                                </label>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Void sale and request owner review
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            )}
            <Button variant="outline" asChild>
                <Link href="/sales/create">Start another sale</Link>
            </Button>
        </div>
    );
}

SaleView.layout = {
    breadcrumbs: [
        { title: 'Sales', href: '/sales' },
        { title: 'Sale details', href: '#' },
    ],
};
