import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Product = {
    id: number;
    sku: string;
    name: string;
    unit_label: string;
    quantity_precision: number;
    quantity_on_hand: string;
    version: number;
    is_active: boolean;
};

export default function CreateCount({
    products,
    submission_key,
    openingReady,
}: {
    products: Product[];
    submission_key: string;
    openingReady: boolean;
}) {
    const [search, setSearch] = useState('');
    const form = useForm({
        submission_key,
        reason: '',
        items: [] as {
            product_id: number;
            expected_version: number;
            counted_quantity: string;
        }[],
    });
    const selected = new Set(form.data.items.map((item) => item.product_id));
    const matches = products
        .filter(
            (p) =>
                !selected.has(p.id) &&
                `${p.sku} ${p.name}`
                    .toLowerCase()
                    .includes(search.toLowerCase()),
        )
        .slice(0, 20);
    return (
        <div className="max-w-4xl space-y-6 p-4 sm:p-6">
            <Head title="Record physical count" />
            <Link href="/stock-counts" className="text-sm underline">
                Back to counts
            </Link>
            <div>
                <h1 className="text-2xl font-semibold">
                    Record a physical stock count
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Count goods while sales and deliveries for these listings
                    are paused. Refresh and recount if anything changes. Counts
                    do not reserve stock.
                </p>
            </div>
            {!openingReady && (
                <p
                    role="alert"
                    className="rounded-xl border bg-secondary p-4 text-secondary-foreground"
                >
                    The owner must sign off opening balances first. Initial
                    counts belong in opening stock review.
                </p>
            )}
            <section className="space-y-3 rounded-xl border bg-card p-5">
                <Label htmlFor="count-search">
                    Find a listing by name or SKU
                </Label>
                <Input
                    id="count-search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search products"
                />
                <div className="grid gap-2 sm:grid-cols-2">
                    {matches.map((p) => (
                        <Button
                            type="button"
                            key={p.id}
                            variant="outline"
                            className="h-auto justify-start text-left whitespace-normal"
                            disabled={
                                !openingReady || form.data.items.length >= 100
                            }
                            onClick={() =>
                                form.setData('items', [
                                    ...form.data.items,
                                    {
                                        product_id: p.id,
                                        expected_version: p.version,
                                        counted_quantity: '',
                                    },
                                ])
                            }
                        >
                            {p.sku} · {p.name} · {p.unit_label}
                            {!p.is_active && ' (inactive)'}
                        </Button>
                    ))}
                </div>
                {matches.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No remaining listings match this search.
                    </p>
                )}
            </section>
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/stock-counts');
                }}
            >
                {Object.entries(form.errors).map(([field, message]) => (
                    <InputError key={field} message={message} />
                ))}
                {form.data.items.map((item, index) => {
                    const product = products.find(
                        (p) => p.id === item.product_id,
                    )!;
                    return (
                        <section
                            key={item.product_id}
                            className="space-y-3 rounded-xl border bg-card p-5"
                        >
                            <div className="flex justify-between gap-4">
                                <div>
                                    <h2 className="font-semibold">
                                        {product.name}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {product.sku} · System:{' '}
                                        {product.quantity_on_hand}{' '}
                                        {product.unit_label}
                                    </p>
                                </div>
                                <Button
                                    variant="ghost"
                                    type="button"
                                    onClick={() =>
                                        form.setData(
                                            'items',
                                            form.data.items.filter(
                                                (row) =>
                                                    row.product_id !==
                                                    item.product_id,
                                            ),
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                            <Label htmlFor={`count-${product.id}`}>
                                Physically counted ({product.unit_label}, up to{' '}
                                {product.quantity_precision} decimal places)
                            </Label>
                            <Input
                                id={`count-${product.id}`}
                                value={item.counted_quantity}
                                inputMode="decimal"
                                required
                                onChange={(e) =>
                                    form.setData(
                                        'items',
                                        form.data.items.map((row, i) =>
                                            i === index
                                                ? {
                                                      ...row,
                                                      counted_quantity:
                                                          e.target.value,
                                                  }
                                                : row,
                                        ),
                                    )
                                }
                            />
                        </section>
                    );
                })}
                <div className="space-y-2">
                    <Label htmlFor="count-reason">
                        Count reason / discrepancy notes
                    </Label>
                    <Input
                        id="count-reason"
                        value={form.data.reason}
                        required
                        maxLength={2000}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                </div>
                <p className="text-sm text-muted-foreground">
                    Enter zero explicitly when no units are found. Only the
                    selected listings are counted. Submission preserves your
                    quantities for owner review.
                </p>
                <Button
                    disabled={
                        !openingReady ||
                        form.processing ||
                        form.data.items.length === 0
                    }
                >
                    Submit count for owner review
                </Button>
            </form>
        </div>
    );
}
CreateCount.layout = {
    breadcrumbs: [
        { title: 'Stock counts', href: '/stock-counts' },
        { title: 'New count', href: '#' },
    ],
};
