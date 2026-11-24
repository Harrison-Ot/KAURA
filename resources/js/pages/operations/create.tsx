import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { minorMoney } from '@/lib/money';

type Props = {
    submissionKey: string;
    sale: { id: number; reference: string; status: string } | null;
    items: {
        id: number;
        name: string;
        quantity: string;
        unit_label: string;
        returned: string;
        total_minor: number;
    }[];
    customer: { id: number; name: string } | null;
    debts: { id: number; due_on: string; remaining_amount_minor: string }[];
    credits: { id: number; remaining_amount_minor: string }[];
    outflow: { id: number; kind: string; amount_minor: number } | null;
};
export default function CreateOperation({
    submissionKey,
    sale,
    items,
    customer,
    debts,
    credits,
    outflow,
}: Props) {
    const [kind, setKind] = useState(
        sale ? 'return' : outflow ? 'reversal' : 'write_off',
    );
    const form = useForm({
        submission_key: submissionKey,
        sale_item_id: String(items[0]?.id ?? ''),
        quantity: '',
        disposition: 'restock',
        receivable_id: String(debts[0]?.id ?? ''),
        customer_credit_id: String(credits[0]?.id ?? ''),
        amount: '',
        payment_method: 'cash',
        payment_reference: '',
        recipient: '',
        reason: '',
        confirm: false,
    });
    const [preview, setPreview] = useState<{
        key: string;
        amount_minor: string;
        cash_amount_minor: string;
        debt_amount_minor: string;
    } | null>(null);
    const [quoteError, setQuoteError] = useState('');
    const [quoting, setQuoting] = useState(false);
    const quoteKey = `${sale?.id}/${form.data.sale_item_id}/${form.data.quantity}/${form.data.disposition}`;
    const previewCurrent = preview?.key === quoteKey;
    async function previewReturn() {
        setQuoting(true);
        setQuoteError('');
        try {
            const params = new URLSearchParams({
                sale_id: String(sale?.id),
                sale_item_id: form.data.sale_item_id,
                quantity: form.data.quantity,
                disposition: form.data.disposition,
            });
            const response = await fetch(`/owner/operations/quote?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok) {
                setQuoteError(
                    Object.values(data.errors ?? {})
                        .flat()
                        .join(' ') || 'Could not preview this return.',
                );
                return;
            }
            setPreview({ ...data, key: quoteKey });
        } catch {
            setQuoteError('Could not load the return preview. Try again.');
        } finally {
            setQuoting(false);
        }
    }
    const inputClass = 'w-full rounded-lg border bg-background p-3 text-sm';
    return (
        <div className="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-8">
            <Head title="Review operation" />
            <Link
                href="/owner/operations"
                className="text-sm text-primary underline"
            >
                Back to history
            </Link>
            <div>
                <p className="text-xs tracking-widest text-muted-foreground">
                    OWNER REVIEW
                </p>
                <h1 className="mt-2 text-3xl font-semibold">
                    {sale
                        ? `Return · ${sale.reference}`
                        : outflow
                          ? `Reverse payment #${outflow.id}`
                          : `Debt review · ${customer?.name ?? ''}`}
                </h1>
                <p className="mt-3 text-sm leading-6 text-muted-foreground">
                    Review the physical goods and financial facts before
                    confirming. This creates a linked permanent record.
                </p>
            </div>
            {!sale && !customer && !outflow ? (
                <p>Select a record from the history page first.</p>
            ) : (
                <form
                    className="space-y-5 rounded-2xl border bg-card p-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            submission_key: data.submission_key,
                            kind,
                            reason: data.reason,
                            confirm: data.confirm,
                            ...(kind === 'return' && preview
                                ? {
                                      expected_amount_minor:
                                          preview.amount_minor,
                                      expected_cash_amount_minor:
                                          preview.cash_amount_minor,
                                      expected_debt_amount_minor:
                                          preview.debt_amount_minor,
                                  }
                                : {}),
                            ...(kind === 'return'
                                ? {
                                      sale_id: sale?.id,
                                      items: [
                                          {
                                              sale_item_id: data.sale_item_id,
                                              quantity: data.quantity,
                                              disposition: data.disposition,
                                          },
                                      ],
                                  }
                                : kind === 'reversal'
                                  ? { cash_outflow_id: outflow?.id }
                                  : {
                                        receivable_id: data.receivable_id,
                                        amount: data.amount,
                                        ...(kind === 'credit_offset'
                                            ? {
                                                  customer_credit_id:
                                                      data.customer_credit_id,
                                              }
                                            : {}),
                                    }),
                            ...(kind === 'return' || kind === 'reversal'
                                ? {
                                      payment_method: data.payment_method,
                                      recipient: data.recipient,
                                      ...(data.payment_method === 'mpesa'
                                          ? {
                                                payment_reference:
                                                    data.payment_reference,
                                            }
                                          : {}),
                                  }
                                : {}),
                        }));
                        form.post('/owner/operations');
                    }}
                >
                    {Object.values(form.errors).map((error, i) => (
                        <p
                            key={i}
                            role="alert"
                            className="text-sm text-destructive"
                        >
                            {error}
                        </p>
                    ))}
                    {customer && (
                        <>
                            <label className="grid gap-2 text-sm">
                                Action
                                <select
                                    className={inputClass}
                                    value={kind}
                                    onChange={(e) => setKind(e.target.value)}
                                >
                                    <option value="write_off">
                                        Write off uncollectible debt
                                    </option>
                                    <option value="credit_offset">
                                        Apply retained customer credit
                                    </option>
                                </select>
                            </label>
                            <label className="grid gap-2 text-sm">
                                Debt (oldest first)
                                <select
                                    required
                                    className={inputClass}
                                    value={form.data.receivable_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'receivable_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {debts.map((debt) => (
                                        <option key={debt.id} value={debt.id}>
                                            #{debt.id} · Due {debt.due_on} ·{' '}
                                            {minorMoney(
                                                debt.remaining_amount_minor,
                                            )}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {kind === 'credit_offset' && (
                                <label className="grid gap-2 text-sm">
                                    Available credit
                                    <select
                                        required
                                        className={inputClass}
                                        value={form.data.customer_credit_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'customer_credit_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {credits.map((credit) => (
                                            <option
                                                key={credit.id}
                                                value={credit.id}
                                            >
                                                Credit #{credit.id} ·{' '}
                                                {minorMoney(
                                                    credit.remaining_amount_minor,
                                                )}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <label className="grid gap-2 text-sm">
                                Amount (KES)
                                <input
                                    required
                                    inputMode="decimal"
                                    value={form.data.amount}
                                    onChange={(e) =>
                                        form.setData('amount', e.target.value)
                                    }
                                    className={inputClass}
                                />
                            </label>
                            <p className="rounded-lg bg-secondary p-4 text-sm">
                                This reduces the debt without recording a cash
                                collection. Credit offsets also reduce the
                                customer credit liability.
                            </p>
                        </>
                    )}
                    {sale && (
                        <>
                            <label className="grid gap-2 text-sm">
                                Original sale item
                                <select
                                    required
                                    className={inputClass}
                                    value={form.data.sale_item_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'sale_item_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {items.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name} · Sold {item.quantity}{' '}
                                            {item.unit_label} · Returned{' '}
                                            {item.returned}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-2 text-sm">
                                Quantity to return
                                <input
                                    className={inputClass}
                                    required
                                    inputMode="decimal"
                                    value={form.data.quantity}
                                    onChange={(e) =>
                                        form.setData('quantity', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-2 text-sm">
                                Physical goods decision
                                <select
                                    className={inputClass}
                                    value={form.data.disposition}
                                    onChange={(e) =>
                                        form.setData(
                                            'disposition',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="restock">
                                        Received and resellable — add to stock
                                    </option>
                                    <option value="damaged">
                                        Received damaged — retain in
                                        damaged-goods history
                                    </option>
                                    <option value="not_received">
                                        Not received — do not add stock
                                    </option>
                                </select>
                            </label>
                            <p className="rounded-lg bg-secondary p-4 text-sm leading-6">
                                The original discounted line value is
                                apportioned exactly. Outstanding debt is reduced
                                first; only previously settled funds can be
                                refunded. Damaged goods remain outside sellable
                                stock.
                            </p>
                        </>
                    )}
                    {outflow && (
                        <p className="rounded-lg bg-secondary p-4 text-sm leading-6">
                            Record {minorMoney(outflow.amount_minor)} actually
                            returned to the shop. For a stock purchase, all
                            delivered goods must be returned to the supplier and
                            have no later stock activity. Post a corrected
                            replacement separately after reversal.
                        </p>
                    )}
                    {(sale || outflow) && (
                        <>
                            <label className="grid gap-2 text-sm">
                                {sale
                                    ? 'Refund recipient'
                                    : 'Person returning money'}
                                <input
                                    required
                                    className={inputClass}
                                    value={form.data.recipient}
                                    onChange={(e) =>
                                        form.setData(
                                            'recipient',
                                            e.target.value,
                                        )
                                    }
                                    maxLength={255}
                                />
                            </label>
                            <label className="grid gap-2 text-sm">
                                {sale
                                    ? 'Refund method (if funds are due)'
                                    : 'Money received through'}
                                <select
                                    className={inputClass}
                                    value={form.data.payment_method}
                                    onChange={(e) =>
                                        form.setData(
                                            'payment_method',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="cash">Cash</option>
                                    <option value="mpesa">M-Pesa</option>
                                </select>
                            </label>
                            {form.data.payment_method === 'mpesa' && (
                                <label className="grid gap-2 text-sm">
                                    New transaction code
                                    <input
                                        required
                                        className={inputClass}
                                        value={form.data.payment_reference}
                                        onChange={(e) =>
                                            form.setData(
                                                'payment_reference',
                                                e.target.value.toUpperCase(),
                                            )
                                        }
                                    />
                                </label>
                            )}
                        </>
                    )}
                    {sale && (
                        <section className="space-y-3 rounded-xl border bg-secondary p-4">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={quoting || !form.data.quantity}
                                onClick={() => void previewReturn()}
                            >
                                {quoting
                                    ? 'Calculating?'
                                    : 'Preview exact return amounts'}
                            </Button>
                            {quoteError && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {quoteError}
                                </p>
                            )}
                            {previewCurrent && preview && (
                                <dl className="grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt>Return value</dt>
                                        <dd className="mt-1 font-semibold">
                                            {minorMoney(preview.amount_minor)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Debt reduction</dt>
                                        <dd className="mt-1 font-semibold">
                                            {minorMoney(
                                                preview.debt_amount_minor,
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Refund to record</dt>
                                        <dd className="mt-1 font-semibold">
                                            {minorMoney(
                                                preview.cash_amount_minor,
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                            )}
                        </section>
                    )}
                    <label className="grid gap-2 text-sm">
                        Reason and review evidence
                        <textarea
                            required
                            maxLength={2000}
                            className={inputClass}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                    </label>
                    <label className="flex items-start gap-3 text-sm leading-6">
                        <input
                            type="checkbox"
                            required
                            checked={form.data.confirm}
                            onChange={(e) =>
                                form.setData('confirm', e.target.checked)
                            }
                            className="mt-1"
                        />
                        I reviewed these goods, balances and payment details,
                        and confirm any refund or money return has actually
                        occurred.
                    </label>
                    <Button
                        disabled={
                            form.processing ||
                            (kind === 'return' && !previewCurrent)
                        }
                    >
                        Confirm and post operation
                    </Button>
                </form>
            )}
        </div>
    );
}
