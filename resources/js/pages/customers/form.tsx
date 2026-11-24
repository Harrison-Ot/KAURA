import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Customer } from '@/types/catalogue';

export default function CustomerForm({
    customer,
}: {
    customer: Customer | null;
}) {
    return (
        <div className="max-w-2xl space-y-6 p-4 sm:p-6">
            <Head title={customer ? 'Edit customer' : 'Add customer'} />
            {customer && (
                <Link
                    href={`/owner/customers/${customer.id}/statement`}
                    className="text-sm underline"
                >
                    View opening debt statement
                </Link>
            )}
            <div>
                <Link href="/customers" className="text-sm underline">
                    Back to customers
                </Link>
                <h1 className="mt-3 text-2xl font-semibold">
                    {customer ? 'Edit customer' : 'Add customer'}
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Use the customer's name and contact as recorded by the shop.
                    Review possible matches before saving.
                </p>
            </div>
            <Form
                key={`details-${customer?.id ?? 'new'}-${customer?.version ?? 0}`}
                action={customer ? `/customers/${customer.id}` : '/customers'}
                method={customer ? 'patch' : 'post'}
                className="space-y-5 rounded-xl border bg-card p-5"
            >
                {({ errors, processing }) => (
                    <>
                        {customer && (
                            <input
                                type="hidden"
                                name="version"
                                value={customer.version}
                            />
                        )}
                        <InputError message={errors.version} />
                        <div className="space-y-2">
                            <Label htmlFor="name">Customer name</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={customer?.name ?? ''}
                                required
                                maxLength={255}
                                autoComplete="name"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="contact">
                                Phone number or contact reference
                            </Label>
                            <Input
                                id="contact"
                                name="contact"
                                defaultValue={customer?.contact ?? ''}
                                required
                                maxLength={100}
                            />
                            <InputError message={errors.contact} />
                        </div>
                        {customer && (
                            <div className="space-y-2">
                                <Label htmlFor="details_reason">
                                    Reason for details change
                                </Label>
                                <Input
                                    id="details_reason"
                                    name="reason"
                                    required
                                    maxLength={1000}
                                />
                                <InputError message={errors.reason} />
                            </div>
                        )}
                        <div className="space-y-2">
                            <label className="flex items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    name="confirm_duplicate"
                                    value="1"
                                    className="mt-1"
                                />
                                I checked the directory and confirm this
                                customer needs a separate record.
                            </label>
                            <InputError message={errors.confirm_duplicate} />
                        </div>
                        <Button disabled={processing}>
                            {processing
                                ? 'Saving…'
                                : customer
                                  ? 'Save customer details'
                                  : 'Create customer'}
                        </Button>
                    </>
                )}
            </Form>
            {customer && (
                <section className="space-y-4 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Owner credit limit</h2>
                    <p className="text-sm text-muted-foreground">
                        This limit sets a ceiling. Each credit sale will still
                        require owner approval.
                    </p>
                    <Form
                        key={`limit-${customer.id}-${customer.version}`}
                        action={`/customers/${customer.id}/credit-limit`}
                        method="patch"
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="version"
                                    value={customer.version}
                                />
                                {errors.version && (
                                    <div role="alert">
                                        <InputError message={errors.version} />
                                        <Link
                                            href={`/customers/${customer.id}/edit`}
                                            className="text-sm underline"
                                        >
                                            Reload current customer
                                        </Link>
                                    </div>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="credit_limit">
                                        Maximum outstanding credit (KES)
                                    </Label>
                                    <Input
                                        id="credit_limit"
                                        name="credit_limit"
                                        defaultValue={
                                            customer.credit_limit ?? '0.00'
                                        }
                                        required
                                        inputMode="decimal"
                                        maxLength={12}
                                    />
                                    <InputError message={errors.credit_limit} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="limit_reason">
                                        Reason for limit change
                                    </Label>
                                    <Input
                                        id="limit_reason"
                                        name="reason"
                                        required
                                        maxLength={1000}
                                    />
                                    <InputError message={errors.reason} />
                                </div>
                                <Button variant="outline" disabled={processing}>
                                    Save credit limit
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            )}
        </div>
    );
}

CustomerForm.layout = {
    breadcrumbs: [
        { title: 'Customers', href: '/customers' },
        { title: 'Customer record', href: '#' },
    ],
};
