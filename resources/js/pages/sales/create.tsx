import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes, quantity } from '@/lib/catalogue';
import {
    decimalAmount,
    lineAmount,
    minorAmount,
    scaledQuantity,
} from '@/lib/checkout';

type Product = {
    id: number;
    sku: string;
    name: string;
    barcode: string | null;
    unit_label: string;
    quantity_precision: number;
    quantity_on_hand: string;
    selling_price: string;
};
type CartItem = { product_id: number; quantity: string; quoted_price: string };

export default function CashCheckout({
    products,
    customers,
    submission_key,
    openingReady,
    mpesaNumber,
}: {
    products: Product[];
    customers: { id: number; name: string; contact: string }[];
    submission_key: string;
    openingReady: boolean;
    mpesaNumber: string | null;
}) {
    const [search, setSearch] = useState('');
    const [proposal, setProposal] = useState({
        discount: '0.00',
        credit: '0.00',
        due: '',
        reason: '',
    });
    const form = useForm({
        submission_key,
        payment_method: 'cash' as 'cash' | 'manual_mpesa',
        cash_tendered: '',
        mpesa_code: '',
        mpesa_amount: '',
        payer_reference: '',
        expected_receiving_number: mpesaNumber ?? '',
        customer_id: '',
        items: [] as CartItem[],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const amounts = form.data.items.map((item) => {
        const product = products.find((entry) => entry.id === item.product_id);
        return product
            ? lineAmount(
                  item.quantity,
                  product.quantity_precision,
                  item.quoted_price,
              )
            : null;
    });
    const valid =
        form.data.items.length > 0 &&
        amounts.every((amount) => amount !== null);
    const total = valid
        ? amounts.reduce<bigint>((sum, amount) => sum + (amount ?? 0n), 0n)
        : null;
    const tendered = minorAmount(form.data.cash_tendered);
    const enough = total !== null && tendered !== null && tendered >= total;
    const manual = form.data.payment_method === 'manual_mpesa';
    const manualAmount = minorAmount(form.data.mpesa_amount);
    const paymentReady = manual
        ? total !== null &&
          total > 0n &&
          manualAmount === total &&
          mpesaNumber !== null &&
          form.data.expected_receiving_number === mpesaNumber &&
          /^[A-Z0-9]{6,30}$/.test(form.data.mpesa_code.trim().toUpperCase())
        : enough;
    const current = form.data.items.every((item) => {
        const product = products.find((entry) => entry.id === item.product_id);
        if (!product || product.selling_price !== item.quoted_price)
            return false;
        const requested = scaledQuantity(
            item.quantity,
            product.quantity_precision,
        );
        const available = scaledQuantity(product.quantity_on_hand, 3);
        return (
            requested !== null &&
            requested > 0n &&
            available !== null &&
            requested <= available
        );
    });
    const matches = products
        .filter((product) =>
            `${product.name} ${product.sku} ${product.barcode ?? ''}`
                .toLowerCase()
                .includes(search.toLowerCase()),
        )
        .slice(0, 30);
    function updateItem(index: number, patch: Partial<CartItem>) {
        form.setData(
            'items',
            form.data.items.map((item, position) =>
                position === index ? { ...item, ...patch } : item,
            ),
        );
    }
    function add(product: Product) {
        if (form.data.items.some((item) => item.product_id === product.id)) {
            document.getElementById(`quantity-${product.id}`)?.focus();
            return;
        }
        form.setData('items', [
            ...form.data.items,
            {
                product_id: product.id,
                quantity: '1',
                quoted_price: product.selling_price,
            },
        ]);
    }
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Checkout" />
            <div className="flex flex-wrap justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Checkout</h1>
                    <p className="text-sm text-muted-foreground">
                        Check the cart and payment details. Stock is rechecked
                        when you post.
                    </p>
                </div>
                <Link href="/sales" className="text-sm underline">
                    View sales
                </Link>
            </div>
            {!openingReady && (
                <p
                    role="alert"
                    className="rounded border border-destructive p-4"
                >
                    The owner must finish shop setup and approve opening
                    balances before sales can be posted.
                </p>
            )}
            <div className="grid items-start gap-6 xl:grid-cols-2">
                <section className="space-y-4 rounded-xl border bg-card p-5">
                    <div className="space-y-2">
                        <Label htmlFor="product_search">Find a product</Label>
                        <Input
                            id="product_search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name, SKU or barcode"
                            autoFocus
                            maxLength={100}
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            router.reload({
                                only: [
                                    'products',
                                    'openingReady',
                                    'mpesaNumber',
                                ],
                            })
                        }
                    >
                        Refresh prices and stock
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        Showing up to 30 matches. Add a product once, then set
                        its quantity in the cart.
                    </p>
                    {matches.length === 0 && <p>No matching products.</p>}
                    <div className="space-y-2">
                        {matches.map((product) => (
                            <button
                                type="button"
                                key={product.id}
                                onClick={() => add(product)}
                                disabled={
                                    !openingReady ||
                                    Number(product.quantity_on_hand) <= 0 ||
                                    form.data.items.length >= 100
                                }
                                className="flex w-full items-center justify-between gap-3 rounded-lg border p-3 text-left hover:bg-muted disabled:opacity-50"
                            >
                                <div>
                                    <p className="font-medium">
                                        {product.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {product.sku} · {product.unit_label} ·
                                        Stock{' '}
                                        {quantity(
                                            product.quantity_on_hand,
                                            product.quantity_precision,
                                        )}
                                    </p>
                                </div>
                                <span className="shrink-0 text-sm">
                                    {kes(product.selling_price)}
                                </span>
                            </button>
                        ))}
                    </div>
                </section>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            submission_key: data.submission_key,
                            payment_method: data.payment_method,
                            customer_id: data.customer_id,
                            items: data.items,
                            ...(data.payment_method === 'cash'
                                ? { cash_tendered: data.cash_tendered }
                                : {
                                      mpesa_code: data.mpesa_code,
                                      mpesa_amount: data.mpesa_amount,
                                      payer_reference: data.payer_reference,
                                      expected_receiving_number:
                                          data.expected_receiving_number,
                                  }),
                        }));
                        form.post('/sales');
                    }}
                    className="space-y-5 rounded-xl border bg-card p-5"
                >
                    <h2 className="text-lg font-semibold">Cart</h2>
                    <Link href="/sale-requests" className="text-sm underline">
                        My approval requests
                    </Link>
                    <p className="text-xs text-muted-foreground">
                        The draft stays on this screen. It does not reserve or
                        deduct stock.
                    </p>
                    {[
                        'checkout',
                        'submission_key',
                        'payment_method',
                        'items',
                    ].map((field) => (
                        <InputError key={field} message={errors[field]} />
                    ))}
                    {form.data.items.length === 0 && (
                        <p className="rounded border border-dashed p-5 text-sm">
                            Search for a product and add it to the cart.
                        </p>
                    )}
                    {form.data.items.map((item, index) => {
                        const product = products.find(
                            (entry) => entry.id === item.product_id,
                        );
                        return (
                            <article
                                key={item.product_id}
                                className="space-y-3 rounded-lg border p-4"
                            >
                                <div className="flex justify-between gap-2">
                                    <div>
                                        <h3 className="font-semibold">
                                            {product?.name ??
                                                'Unavailable product'}
                                        </h3>
                                        <p className="text-xs text-muted-foreground">
                                            {product?.sku} ·{' '}
                                            {kes(item.quoted_price)} per{' '}
                                            {product?.unit_label}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() =>
                                            form.setData(
                                                'items',
                                                form.data.items.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                                <InputError
                                    message={
                                        errors[`items.${index}.product_id`]
                                    }
                                />
                                <InputError
                                    message={
                                        errors[`items.${index}.quoted_price`]
                                    }
                                />
                                {product &&
                                    product.selling_price !==
                                        item.quoted_price && (
                                        <div className="space-y-2">
                                            <p className="text-sm">
                                                Current price:{' '}
                                                {kes(product.selling_price)}.
                                                Confirm it before posting.
                                            </p>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    updateItem(index, {
                                                        quoted_price:
                                                            product.selling_price,
                                                    })
                                                }
                                            >
                                                Use current price
                                            </Button>
                                        </div>
                                    )}
                                <Label htmlFor={`quantity-${item.product_id}`}>
                                    Quantity ({product?.unit_label})
                                </Label>
                                <Input
                                    id={`quantity-${item.product_id}`}
                                    value={item.quantity}
                                    onChange={(event) =>
                                        updateItem(index, {
                                            quantity: event.target.value,
                                        })
                                    }
                                    inputMode="decimal"
                                    required
                                    maxLength={13}
                                />
                                <InputError
                                    message={errors[`items.${index}.quantity`]}
                                />
                                <p className="text-sm">
                                    Line total:{' '}
                                    {amounts[index] === null
                                        ? 'Check quantity'
                                        : kes(
                                              decimalAmount(
                                                  amounts[index] ?? 0n,
                                              ),
                                          )}
                                </p>
                                {product && (
                                    <p className="text-xs text-muted-foreground">
                                        Available:{' '}
                                        {quantity(
                                            product.quantity_on_hand,
                                            product.quantity_precision,
                                        )}{' '}
                                        {product.unit_label}. Quantities allow{' '}
                                        {product.quantity_precision} decimal
                                        places.
                                    </p>
                                )}
                            </article>
                        );
                    })}
                    <div className="space-y-2">
                        <Label htmlFor="customer_id">
                            Customer (optional; helpful for payment follow-up)
                        </Label>
                        <select
                            id="customer_id"
                            value={form.data.customer_id}
                            onChange={(event) =>
                                form.setData('customer_id', event.target.value)
                            }
                            className="h-9 w-full rounded border bg-background px-3 text-sm"
                        >
                            <option value="">Walk-in customer</option>
                            {customers.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.name} · {customer.contact}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.customer_id} />
                    </div>
                    <div className="border-t pt-4">
                        <p className="text-xl font-semibold">
                            Total:{' '}
                            {total === null ? '—' : kes(decimalAmount(total))}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Standard prices ·{' '}
                            {manual
                                ? 'M-Pesa pending owner verification'
                                : 'Cash paid in full'}
                        </p>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="payment_method">Payment method</Label>
                        <select
                            id="payment_method"
                            value={form.data.payment_method}
                            onChange={(event) =>
                                form.setData(
                                    'payment_method',
                                    event.target.value as
                                        | 'cash'
                                        | 'manual_mpesa',
                                )
                            }
                            className="h-9 w-full rounded border bg-background px-3 text-sm"
                        >
                            <option value="cash">Cash</option>
                            <option
                                value="manual_mpesa"
                                disabled={!mpesaNumber}
                            >
                                Manual M-Pesa
                            </option>
                        </select>
                        <InputError message={errors.payment_method} />
                    </div>
                    {manual ? (
                        <section className="space-y-4 rounded-lg border p-4">
                            <div>
                                <p className="text-sm">
                                    Ask the customer to pay this number:
                                </p>
                                <p className="text-xl font-semibold">
                                    {form.data.expected_receiving_number ||
                                        'Not configured'}
                                </p>
                            </div>
                            <InputError
                                message={errors.expected_receiving_number}
                            />
                            {form.data.expected_receiving_number !==
                                mpesaNumber && (
                                <div className="space-y-2">
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        The receiving number changed. Confirm
                                        the destination with the owner before
                                        recording a code.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            form.setData({
                                                ...form.data,
                                                expected_receiving_number:
                                                    mpesaNumber ?? '',
                                                mpesa_code: '',
                                                mpesa_amount: '',
                                                payer_reference: '',
                                            })
                                        }
                                    >
                                        Use updated number and clear old payment
                                        details
                                    </Button>
                                </div>
                            )}
                            {(
                                [
                                    ['mpesa_code', 'M-Pesa transaction code'],
                                    ['mpesa_amount', 'Amount sent (KES)'],
                                    [
                                        'payer_reference',
                                        'Payer name / reference (optional)',
                                    ],
                                ] as const
                            ).map(([field, label]) => (
                                <div key={field} className="space-y-2">
                                    <Label htmlFor={field}>{label}</Label>
                                    <Input
                                        id={field}
                                        value={form.data[field]}
                                        onChange={(event) =>
                                            form.setData(
                                                field,
                                                event.target.value,
                                            )
                                        }
                                        required={field !== 'payer_reference'}
                                        maxLength={
                                            field === 'payer_reference'
                                                ? 255
                                                : field === 'mpesa_code'
                                                  ? 30
                                                  : 17
                                        }
                                    />
                                    <InputError message={errors[field]} />
                                </div>
                            ))}
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                This records an unverified claim. Only the owner
                                can confirm receipt. Without a transaction code,
                                keep the cart as a draft; do not post it as
                                successful.
                            </p>
                        </section>
                    ) : (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="cash_tendered">
                                    Cash received (KES)
                                </Label>
                                <Input
                                    id="cash_tendered"
                                    value={form.data.cash_tendered}
                                    onChange={(event) =>
                                        form.setData(
                                            'cash_tendered',
                                            event.target.value,
                                        )
                                    }
                                    inputMode="decimal"
                                    required
                                    maxLength={17}
                                />
                                <InputError message={errors.cash_tendered} />
                            </div>
                            <p className="font-semibold">
                                Change:{' '}
                                {enough && tendered !== null && total !== null
                                    ? kes(decimalAmount(tendered - total))
                                    : '—'}
                            </p>
                        </>
                    )}
                    <details className="space-y-3 rounded border p-4">
                        <summary className="cursor-pointer font-semibold">
                            Request owner discount / credit approval
                        </summary>
                        <p className="mt-3 text-sm text-muted-foreground">
                            The saved request fixes this cart, customer and
                            deposit method. Credit requires a named customer and
                            promised date. No stock is reserved.
                        </p>
                        {(
                            [
                                ['discount', 'Requested discount (KES)'],
                                ['credit', 'Requested credit balance (KES)'],
                                ['due', 'Promised repayment date'],
                                ['reason', 'Request reason'],
                            ] as const
                        ).map(([field, label]) => (
                            <div key={field} className="space-y-2">
                                <Label htmlFor={`proposal-${field}`}>
                                    {label}
                                </Label>
                                <Input
                                    id={`proposal-${field}`}
                                    value={proposal[field]}
                                    onChange={(event) =>
                                        setProposal({
                                            ...proposal,
                                            [field]: event.target.value,
                                        })
                                    }
                                    type={field === 'due' ? 'date' : 'text'}
                                />
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                form.processing ||
                                !openingReady ||
                                !current ||
                                !valid
                            }
                            onClick={() => {
                                form.transform((data) => ({
                                    submission_key: data.submission_key,
                                    payment_method: data.payment_method,
                                    customer_id: data.customer_id,
                                    items: data.items,
                                    discount_amount: proposal.discount,
                                    credit_amount: proposal.credit,
                                    due_on: proposal.due,
                                    reason: proposal.reason,
                                }));
                                form.post('/sale-requests');
                            }}
                        >
                            Save cart and request approval
                        </Button>
                    </details>
                    <InputError message={errors.approval} />
                    <InputError message={errors.discount_amount} />
                    <InputError message={errors.credit_amount} />
                    <InputError message={errors.due_on} />
                    <InputError message={errors.reason} />
                    <Button
                        disabled={
                            form.processing ||
                            !openingReady ||
                            !paymentReady ||
                            !current
                        }
                        className="w-full"
                    >
                        {form.processing
                            ? 'Posting sale…'
                            : manual
                              ? 'Record pending M-Pesa and post sale'
                              : 'Confirm cash payment and post sale'}
                    </Button>
                    {form.data.items.length > 0 && !current && (
                        <p role="status" className="text-sm text-destructive">
                            Check quantities, available stock and current prices
                            before posting.
                        </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                        A successful checkout opens the saved sale and receipt.
                        Retry a failed connection with the same cart to avoid
                        duplicate posting.
                    </p>
                </form>
            </div>
        </div>
    );
}

CashCheckout.layout = {
    breadcrumbs: [
        { title: 'Sales', href: '/sales' },
        { title: 'Checkout', href: '/sales/create' },
    ],
};
