import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { Auth } from '@/types';
import type { Approval } from '@/types/approvals';

export default function ApprovalDetails({
    approval,
    mpesaNumber,
    creditReview,
}: {
    approval: Approval;
    mpesaNumber: string | null;
    creditReview: { limit: string; outstanding: string } | null;
}) {
    const { user } = usePage<{ auth: Auth }>().props.auth;
    const manual = approval.payment_method === 'manual_mpesa';
    return (
        <div className="max-w-3xl space-y-6 p-6">
            <Head title={`Approval #${approval.id}`} />
            <Link href="/sale-requests" className="text-sm underline">
                Back to approvals
            </Link>
            <h1 className="text-2xl font-semibold">
                Request #{approval.id} · {approval.status}
            </h1>
            <Button
                type="button"
                variant="outline"
                onClick={() => router.reload()}
            >
                Refresh status
            </Button>
            <section className="space-y-2 rounded-xl border bg-card p-5">
                <p>Requested by: {approval.requested_by_name}</p>
                <p>
                    Customer:{' '}
                    {approval.customer_snapshot?.name ?? 'Walk-in customer'}
                </p>
                <p>
                    Gross {kes(approval.gross)} · Discount{' '}
                    {kes(approval.discount)} · Total {kes(approval.total)}
                </p>
                <p>
                    Credit {kes(approval.credit)} · Deposit{' '}
                    {kes(approval.deposit)} by{' '}
                    {manual ? 'manual M-Pesa' : 'cash'}
                </p>
                {approval.due_on && (
                    <p>Promised repayment date: {approval.due_on}</p>
                )}
                <p>Request reason: {approval.reason}</p>
                {approval.decision_reason && (
                    <p>Owner decision: {approval.decision_reason}</p>
                )}
                {approval.expires_at && (
                    <p>
                        Approval expiry:{' '}
                        {new Date(approval.expires_at).toLocaleString('en-KE', {
                            timeZone: 'Africa/Nairobi',
                        })}{' '}
                        Nairobi
                    </p>
                )}
            </section>
            <section className="space-y-2">
                <h2 className="font-semibold">Locked cart</h2>
                {approval.items.map((item, index) => (
                    <p key={item.product_id} className="rounded border p-3">
                        {index + 1}. {item.name} ({item.sku}) · {item.quantity}{' '}
                        {item.unit_label} at {kes(item.quoted_price)}
                    </p>
                ))}
                <p className="text-xs text-muted-foreground">
                    Changing items, customer or payment method requires a fresh
                    request. Stock and prices are rechecked at posting.
                </p>
            </section>
            {creditReview && (
                <p className="rounded border p-4">
                    Owner review: customer limit {kes(creditReview.limit)} ·
                    Current outstanding {kes(creditReview.outstanding)}
                </p>
            )}
            {user.role === 'owner' && approval.status === 'pending' && (
                <section className="space-y-3 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Owner review</h2>
                    <Form
                        action={`/owner/sale-requests/${approval.id}/decision`}
                        method="post"
                        className="space-y-4"
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
                                <div className="space-y-2">
                                    <Label htmlFor="decision">Decision</Label>
                                    <select
                                        id="decision"
                                        name="decision"
                                        className="h-9 w-full rounded border bg-background px-3"
                                    >
                                        <option value="approved">
                                            Approve exact terms
                                        </option>
                                        <option value="rejected">
                                            Reject request
                                        </option>
                                    </select>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="minutes">
                                        Approval lifetime (minutes, up to 60)
                                    </Label>
                                    <Input
                                        id="minutes"
                                        name="minutes"
                                        type="number"
                                        min="1"
                                        max="60"
                                        defaultValue="30"
                                        required
                                    />
                                </div>
                                <Label htmlFor="reason">Decision reason</Label>
                                <Input
                                    id="reason"
                                    name="reason"
                                    required
                                    maxLength={2000}
                                />
                                <label className="flex gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        name="confirm"
                                        value="1"
                                        required
                                    />
                                    I reviewed the exact customer, cart,
                                    discount, deposit, credit and promised date.
                                </label>
                                <Button disabled={processing}>
                                    Save owner decision
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            )}
            {approval.status === 'approved' &&
                approval.requested_by === user.id && (
                    <section className="space-y-4 rounded-xl border bg-card p-5">
                        <h2 className="font-semibold">Complete this sale</h2>
                        <p>
                            Collect {kes(approval.deposit)} now.{' '}
                            {manual &&
                                'This deposit remains pending until owner verification.'}
                        </p>
                        <Form
                            key={`${approval.id}-${mpesaNumber}`}
                            action="/sales"
                            method="post"
                            className="space-y-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="submission_key"
                                        value={approval.checkout_key}
                                    />
                                    <input
                                        type="hidden"
                                        name="approval_id"
                                        value={approval.id}
                                    />
                                    <input
                                        type="hidden"
                                        name="customer_id"
                                        value={approval.customer_id ?? ''}
                                    />
                                    <input
                                        type="hidden"
                                        name="payment_method"
                                        value={approval.payment_method}
                                    />
                                    {approval.items.map((item, index) => (
                                        <div key={item.product_id}>
                                            <input
                                                type="hidden"
                                                name={`items[${index}][product_id]`}
                                                value={item.product_id}
                                            />
                                            <input
                                                type="hidden"
                                                name={`items[${index}][quantity]`}
                                                value={item.quantity}
                                            />
                                            <input
                                                type="hidden"
                                                name={`items[${index}][quoted_price]`}
                                                value={item.quoted_price}
                                            />
                                        </div>
                                    ))}
                                    {Object.entries(errors).map(
                                        ([field, message]) => (
                                            <InputError
                                                key={field}
                                                message={message}
                                            />
                                        ),
                                    )}
                                    {manual ? (
                                        <>
                                            <p>
                                                Receiving number:{' '}
                                                <strong>
                                                    {mpesaNumber ??
                                                        'Not configured'}
                                                </strong>
                                            </p>
                                            <input
                                                type="hidden"
                                                name="expected_receiving_number"
                                                value={mpesaNumber ?? ''}
                                            />
                                            <Label htmlFor="mpesa_code">
                                                M-Pesa code
                                            </Label>
                                            <Input
                                                id="mpesa_code"
                                                name="mpesa_code"
                                                required
                                                maxLength={30}
                                            />
                                            <Label htmlFor="mpesa_amount">
                                                Amount sent (KES)
                                            </Label>
                                            <Input
                                                id="mpesa_amount"
                                                name="mpesa_amount"
                                                required
                                                inputMode="decimal"
                                            />
                                            <Label htmlFor="payer_reference">
                                                Payer reference (optional)
                                            </Label>
                                            <Input
                                                id="payer_reference"
                                                name="payer_reference"
                                                maxLength={255}
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <Label htmlFor="cash_tendered">
                                                Cash tendered (KES; zero for
                                                full credit)
                                            </Label>
                                            <Input
                                                id="cash_tendered"
                                                name="cash_tendered"
                                                required
                                                inputMode="decimal"
                                            />
                                        </>
                                    )}
                                    <Button
                                        disabled={
                                            processing ||
                                            (manual && !mpesaNumber)
                                        }
                                    >
                                        Post approved sale once
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}
            {approval.consumed_sale_id && (
                <Link
                    href={`/sales/${approval.consumed_sale_id}`}
                    className="text-sm underline"
                >
                    Open the posted sale
                </Link>
            )}
            {['expired', 'rejected'].includes(approval.status) && (
                <p className="rounded border p-4">
                    Build a fresh cart and request approval again. This request
                    cannot post a sale.
                </p>
            )}
        </div>
    );
}

ApprovalDetails.layout = {
    breadcrumbs: [
        { title: 'Sale approvals', href: '/sale-requests' },
        { title: 'Request details', href: '#' },
    ],
};
