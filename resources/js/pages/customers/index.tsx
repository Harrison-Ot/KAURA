import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import ListPagination from '@/components/list-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { Auth } from '@/types';
import type { Customer, Paginated } from '@/types/catalogue';

export default function Customers({
    customers,
    filters,
    status,
}: {
    customers: Paginated<Customer>;
    filters: { q: string };
    status?: string;
}) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Customers" />
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Customers</h1>
                    <p className="text-sm text-muted-foreground">
                        Search existing contacts before adding a customer.
                    </p>
                </div>
                <Button asChild>
                    <Link href="/customers/create">Add customer</Link>
                </Button>
            </div>
            {status && (
                <p role="status" className="rounded-lg border p-4">
                    {status}
                </p>
            )}
            <Form
                action="/customers"
                method="get"
                className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="min-w-0 flex-1 space-y-2">
                            <Label htmlFor="q">Search name or contact</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                maxLength={100}
                            />
                            <InputError message={errors.q} />
                        </div>
                        <Button disabled={processing}>Search</Button>
                        <Link
                            href="/customers"
                            className="py-2 text-sm underline"
                        >
                            Reset
                        </Link>
                    </>
                )}
            </Form>
            {customers.data.length === 0 && (
                <p className="rounded-xl border border-dashed p-8 text-center">
                    No matching customers. Try a different name or contact.
                </p>
            )}
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {customers.data.map((customer) => (
                    <article
                        key={customer.id}
                        className="space-y-3 rounded-xl border bg-card p-5"
                    >
                        <h2 className="font-semibold wrap-anywhere">
                            {customer.name}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Customer ID: {customer.id}
                        </p>
                        <p className="text-sm wrap-anywhere">
                            {customer.contact}
                        </p>
                        {owner && (
                            <>
                                <p className="text-sm">
                                    Credit limit:{' '}
                                    {kes(customer.credit_limit ?? '0.00')}
                                </p>
                                <Link
                                    href={`/customers/${customer.id}/edit`}
                                    className="inline-block text-sm font-medium underline"
                                >
                                    Edit details and credit limit
                                </Link>
                            </>
                        )}
                    </article>
                ))}
            </div>
            <ListPagination {...customers} />
        </div>
    );
}

Customers.layout = {
    breadcrumbs: [{ title: 'Customers', href: '/customers' }],
};
