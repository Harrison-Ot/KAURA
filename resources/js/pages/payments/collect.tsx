import { Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function CollectPayment({
    customers,
    submission_key,
    mpesaNumber,
}: {
    customers: { id: number; name: string; contact: string }[];
    submission_key: string;
    mpesaNumber: string | null;
}) {
    const form = useForm({
        submission_key,
        customer_id: '',
        method: 'cash',
        amount: '',
        mpesa_code: '',
        expected_receiving_number: mpesaNumber ?? '',
        payer_reference: '',
    });
    const manual = form.data.method === 'manual_mpesa';
    return (
        <div className="max-w-2xl space-y-6 p-6">
            <Head title="Collect instalment" />
            <Link href="/payments" className="text-sm underline">
                Back to collections
            </Link>
            <h1 className="text-2xl font-semibold">
                Collect a customer instalment
            </h1>
            <p className="text-sm text-muted-foreground">
                Confirmed funds settle the oldest eligible debt first. Pending
                M-Pesa does not reduce debt and cannot be collected again.
            </p>
            <form
                className="space-y-4 rounded-xl border bg-card p-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        submission_key: data.submission_key,
                        customer_id: data.customer_id,
                        method: data.method,
                        amount: data.amount,
                        ...(manual
                            ? {
                                  mpesa_code: data.mpesa_code,
                                  expected_receiving_number:
                                      data.expected_receiving_number,
                                  payer_reference: data.payer_reference,
                              }
                            : {}),
                    }));
                    form.post('/payments/collect');
                }}
            >
                {Object.entries(form.errors).map(([field, message]) => (
                    <InputError key={field} message={message} />
                ))}
                <Label htmlFor="customer_id">Customer</Label>
                <select
                    id="customer_id"
                    value={form.data.customer_id}
                    onChange={(event) =>
                        form.setData('customer_id', event.target.value)
                    }
                    required
                    className="h-9 w-full rounded border bg-background px-3"
                >
                    <option value="">Select customer</option>
                    {customers.map((customer) => (
                        <option key={customer.id} value={customer.id}>
                            {customer.name} · {customer.contact}
                        </option>
                    ))}
                </select>
                <Label htmlFor="amount">Amount received / claimed (KES)</Label>
                <Input
                    id="amount"
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                    required
                    inputMode="decimal"
                />
                <Label htmlFor="method">Payment method</Label>
                <select
                    id="method"
                    value={form.data.method}
                    onChange={(event) =>
                        form.setData('method', event.target.value)
                    }
                    className="h-9 w-full rounded border bg-background px-3"
                >
                    <option value="cash">Cash — confirmed now</option>
                    <option value="manual_mpesa" disabled={!mpesaNumber}>
                        Manual M-Pesa — pending verification
                    </option>
                </select>
                {manual && (
                    <section className="space-y-3 rounded border p-4">
                        <p>
                            Receiving number:{' '}
                            <strong>
                                {form.data.expected_receiving_number ||
                                    'Not configured'}
                            </strong>
                        </p>
                        <Label htmlFor="mpesa_code">Transaction code</Label>
                        <Input
                            id="mpesa_code"
                            value={form.data.mpesa_code}
                            onChange={(event) =>
                                form.setData('mpesa_code', event.target.value)
                            }
                            required
                            maxLength={30}
                        />
                        <Label htmlFor="payer_reference">
                            Payer reference (optional)
                        </Label>
                        <Input
                            id="payer_reference"
                            value={form.data.payer_reference}
                            onChange={(event) =>
                                form.setData(
                                    'payer_reference',
                                    event.target.value,
                                )
                            }
                            maxLength={255}
                        />
                        <p className="text-sm">
                            The owner must check the code and amount before this
                            claim settles debt.
                        </p>
                    </section>
                )}
                <Button disabled={form.processing}>
                    {form.processing
                        ? 'Recording…'
                        : manual
                          ? 'Record pending instalment'
                          : 'Confirm cash and allocate oldest-first'}
                </Button>
            </form>
        </div>
    );
}

CollectPayment.layout = {
    breadcrumbs: [
        { title: 'Collections', href: '/payments' },
        { title: 'Collect instalment', href: '/payments/collect' },
    ],
};
