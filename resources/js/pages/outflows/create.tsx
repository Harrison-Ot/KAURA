import { Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function CreateOutflow({
    submission_key,
    openingReady,
    today,
}: {
    submission_key: string;
    openingReady: boolean;
    today: string;
}) {
    const form = useForm({
        submission_key,
        kind: 'expense',
        category: 'rent',
        amount: '',
        payment_method: 'cash',
        payment_reference: '',
        recipient: '',
        reason: '',
        confirm: false,
    });
    const expense = form.data.kind === 'expense';
    const mpesa = form.data.payment_method === 'mpesa';
    return (
        <div className="max-w-2xl space-y-6 p-4 sm:p-6">
            <Head title="Record money out" />
            <Link href="/owner/outflows" className="text-sm underline">
                Back to money out
            </Link>
            <div>
                <h1 className="text-2xl font-semibold">
                    Record an expense or withdrawal
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Record funds already paid out. Entries post to {today} in
                    Nairobi and remain in history.
                </p>
            </div>
            {!openingReady && (
                <p
                    role="alert"
                    className="rounded-xl border bg-secondary p-4 text-secondary-foreground"
                >
                    Sign off opening balances before recording new money out.
                </p>
            )}
            <form
                className="space-y-4 rounded-xl border bg-card p-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        submission_key: data.submission_key,
                        kind: data.kind,
                        amount: data.amount,
                        payment_method: data.payment_method,
                        recipient: data.recipient,
                        reason: data.reason,
                        confirm: data.confirm,
                        ...(expense ? { category: data.category } : {}),
                        ...(mpesa
                            ? { payment_reference: data.payment_reference }
                            : {}),
                    }));
                    form.post('/owner/outflows');
                }}
            >
                {Object.entries(form.errors).map(([field, message]) => (
                    <InputError key={field} message={message} />
                ))}
                <Label htmlFor="kind">Type</Label>
                <select
                    id="kind"
                    value={form.data.kind}
                    onChange={(e) => form.setData('kind', e.target.value)}
                    className="h-10 w-full rounded border border-input bg-background px-3"
                >
                    <option value="expense">Operating expense</option>
                    <option value="owner_withdrawal">Owner withdrawal</option>
                </select>
                <p className="text-sm text-muted-foreground">
                    {expense
                        ? 'Rent, wages, utilities and other operating costs. Record stock purchases through supplier deliveries.'
                        : 'Money taken by the owner. Reduces shop funds; excluded from operating expenses and profit deductions.'}
                </p>
                {expense && (
                    <>
                        <Label htmlFor="category">Expense category</Label>
                        <select
                            id="category"
                            value={form.data.category}
                            onChange={(e) =>
                                form.setData('category', e.target.value)
                            }
                            className="h-10 w-full rounded border border-input bg-background px-3"
                        >
                            {[
                                'rent',
                                'transport',
                                'electricity',
                                'wages',
                                'other',
                            ].map((category) => (
                                <option key={category} value={category}>
                                    {category[0].toUpperCase() +
                                        category.slice(1)}
                                </option>
                            ))}
                        </select>
                    </>
                )}
                <Label htmlFor="amount">Amount paid out (KES)</Label>
                <Input
                    id="amount"
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                    inputMode="decimal"
                    required
                />
                <Label htmlFor="method">Paid from</Label>
                <select
                    id="method"
                    value={form.data.payment_method}
                    onChange={(e) => {
                        form.setData('payment_method', e.target.value);
                        form.setData('payment_reference', '');
                    }}
                    className="h-10 w-full rounded border border-input bg-background px-3"
                >
                    <option value="cash">Cash</option>
                    <option value="mpesa">M-Pesa</option>
                </select>
                {mpesa && (
                    <>
                        <Label htmlFor="code">
                            Outgoing M-Pesa transaction code
                        </Label>
                        <Input
                            id="code"
                            value={form.data.payment_reference}
                            onChange={(e) =>
                                form.setData(
                                    'payment_reference',
                                    e.target.value,
                                )
                            }
                            required
                            maxLength={30}
                        />
                        <p className="text-sm text-muted-foreground">
                            Check your sent-payment message. An incoming or
                            previously recorded outgoing code cannot be reused.
                        </p>
                    </>
                )}
                <Label htmlFor="recipient">
                    Recipient / person receiving the funds
                </Label>
                <Input
                    id="recipient"
                    value={form.data.recipient}
                    onChange={(e) => form.setData('recipient', e.target.value)}
                    required
                    maxLength={255}
                />
                <Label htmlFor="reason">Reason and supporting reference</Label>
                <Input
                    id="reason"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    required
                    maxLength={2000}
                />
                <label className="flex items-start gap-3 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.confirm}
                        onChange={(e) =>
                            form.setData('confirm', e.target.checked)
                        }
                        required
                        className="mt-1"
                    />
                    I checked the amount, recipient and type. These funds have
                    left the shop and have not already been recorded.
                </label>
                <Button disabled={form.processing || !openingReady}>
                    Post {expense ? 'operating expense' : 'owner withdrawal'}
                </Button>
            </form>
        </div>
    );
}
CreateOutflow.layout = {
    breadcrumbs: [
        { title: 'Money out', href: '/owner/outflows' },
        { title: 'New entry', href: '#' },
    ],
};
