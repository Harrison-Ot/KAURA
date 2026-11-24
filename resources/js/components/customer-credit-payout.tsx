import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';

export default function CustomerCreditPayout({
    id,
    amount,
    submissionKey,
}: {
    id: number;
    amount: string;
    submissionKey: string;
}) {
    const form = useForm({
        submission_key: submissionKey,
        payment_method: 'cash',
        payment_reference: '',
        recipient: '',
        reason: '',
        confirm: false,
    });
    const mpesa = form.data.payment_method === 'mpesa';
    return (
        <form
            className="space-y-3 rounded-xl border bg-card p-4 text-card-foreground"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform(({ payment_reference, ...data }) => ({
                    ...data,
                    ...(mpesa ? { payment_reference } : {}),
                }));
                form.post(`/owner/customer-credits/${id}/payout`);
            }}
        >
            <h3 className="font-semibold">
                Return retained credit: {kes(amount)}
            </h3>
            <p className="text-sm text-muted-foreground">
                Record the full balance already returned to this customer. This
                does not spend credit at checkout or offset debt.
            </p>
            {Object.entries(form.errors).map(([field, message]) => (
                <InputError key={field} message={message} />
            ))}
            <Label htmlFor={`credit-method-${id}`}>Payout method</Label>
            <select
                id={`credit-method-${id}`}
                value={form.data.payment_method}
                onChange={(event) => {
                    form.setData('payment_method', event.target.value);
                    form.setData('payment_reference', '');
                }}
                className="h-10 w-full rounded border border-input bg-background px-3"
            >
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
            </select>
            {mpesa && (
                <>
                    <Label htmlFor={`credit-code-${id}`}>
                        Outgoing M-Pesa code
                    </Label>
                    <Input
                        id={`credit-code-${id}`}
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
            <Label htmlFor={`credit-recipient-${id}`}>Recipient</Label>
            <Input
                id={`credit-recipient-${id}`}
                value={form.data.recipient}
                onChange={(event) =>
                    form.setData('recipient', event.target.value)
                }
                required
                maxLength={255}
            />
            <Label htmlFor={`credit-reason-${id}`}>
                Reason / payment evidence
            </Label>
            <Input
                id={`credit-reason-${id}`}
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
                I returned this full amount, confirmed the recipient and checked
                that it was not paid previously.
            </label>
            <Button disabled={form.processing}>
                Record completed credit payout
            </Button>
        </form>
    );
}
