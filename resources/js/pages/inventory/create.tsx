import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { batchLabels } from '@/types/inventory';
import type { BatchKind } from '@/types/inventory';

type Row = {
    sku?: string;
    unit?: string;
    quantity?: string;
    unit_cost?: string;
    customer_id?: string;
    amount?: string;
    due_on?: string;
};
type Data = {
    kind: BatchKind;
    submission_key: string;
    effective_on: string;
    source_note: string;
    rows: Row[];
    csv: File | null;
    supplier_name?: string;
    delivery_reference?: string;
    amount_paid?: string;
    payment_method?: string;
    payment_reference?: string;
};
type Props = {
    kind: BatchKind;
    submission_key: string;
    today: string;
    settings: {
        opening_as_of: string | null;
        opening_completed_at: string | null;
    };
    products: {
        sku: string;
        name: string;
        unit_label: string;
        is_active: boolean;
    }[];
    customers: { id: number; name: string; contact: string }[];
};

export default function CreateBatch({
    kind,
    submission_key,
    today,
    settings,
    products,
    customers,
}: Props) {
    const debt = kind === 'opening_debt';
    const receipt = kind === 'stock_receipt';
    const [csvMode, setCsvMode] = useState(false);
    const emptyRow = (): Row =>
        debt
            ? { customer_id: '', amount: '', due_on: today }
            : { sku: '', unit: '', quantity: '', unit_cost: '' };
    const form = useForm<Data>({
        kind,
        submission_key,
        effective_on: receipt ? today : (settings.opening_as_of ?? today),
        source_note: '',
        rows: [emptyRow()],
        csv: null,
        ...(receipt
            ? {
                  supplier_name: '',
                  delivery_reference: '',
                  amount_paid: '',
                  payment_method: 'cash',
                  payment_reference: '',
              }
            : {}),
    });
    const errors = form.errors as Record<string, string | undefined>;
    function updateRow(index: number, patch: Partial<Row>) {
        form.setData(
            'rows',
            form.data.rows.map((row, position) =>
                position === index ? { ...row, ...patch } : row,
            ),
        );
    }
    const blocked = receipt
        ? settings.opening_completed_at === null
        : settings.opening_completed_at !== null;
    return (
        <div className="max-w-5xl space-y-6 p-4 sm:p-6">
            <Head title={batchLabels[kind]} />
            <div>
                <Link href="/owner/inventory" className="text-sm underline">
                    Back to stock and opening balances
                </Link>
                <h1 className="mt-3 text-2xl font-semibold">
                    {batchLabels[kind]}
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Saving creates a review draft. Stock, debt and money change
                    only after owner confirmation.
                </p>
            </div>
            {blocked && (
                <p
                    role="alert"
                    className="rounded border border-destructive p-4"
                >
                    {receipt
                        ? 'Sign off the opening balances before receiving deliveries.'
                        : 'Opening balances are signed off. Use adjustment records for later corrections.'}
                </p>
            )}
            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => {
                        const result = {
                            ...data,
                            rows: csvMode ? [] : data.rows,
                            csv: csvMode ? data.csv : null,
                        };
                        if (result.payment_method !== 'mpesa') {
                            delete result.payment_reference;
                        }
                        return result;
                    });
                    form.post('/owner/inventory', { forceFormData: true });
                }}
            >
                {['batch', 'submission_key', 'rows'].map((key) => (
                    <InputError key={key} message={errors[key]} />
                ))}
                <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="effective_on">
                            {receipt ? 'Delivery date' : 'Opening as-of date'}
                        </Label>
                        <Input
                            id="effective_on"
                            type="date"
                            max={today}
                            value={form.data.effective_on}
                            onChange={(event) =>
                                form.setData('effective_on', event.target.value)
                            }
                            required
                        />
                        <InputError message={errors.effective_on} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="source_note">
                            Paper source / delivery note
                        </Label>
                        <Input
                            id="source_note"
                            value={form.data.source_note}
                            onChange={(event) =>
                                form.setData('source_note', event.target.value)
                            }
                            required
                            maxLength={2000}
                        />
                        <InputError message={errors.source_note} />
                    </div>
                    {receipt && (
                        <>
                            {(
                                [
                                    ['supplier_name', 'Supplier name'],
                                    [
                                        'delivery_reference',
                                        'Unique supplier delivery reference',
                                    ],
                                    [
                                        'amount_paid',
                                        'Amount paid in full (KES)',
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
                                        required
                                        maxLength={
                                            field === 'supplier_name'
                                                ? 255
                                                : field === 'amount_paid'
                                                  ? 17
                                                  : 100
                                        }
                                    />
                                    <InputError message={errors[field]} />
                                </div>
                            ))}
                            <div className="space-y-2">
                                <Label htmlFor="payment_method">
                                    Supplier payment method
                                </Label>
                                <select
                                    id="payment_method"
                                    value={form.data.payment_method}
                                    onChange={(event) =>
                                        form.setData(
                                            'payment_method',
                                            event.target.value,
                                        )
                                    }
                                    className="h-9 w-full rounded border bg-background px-3 text-sm"
                                >
                                    <option value="cash">Cash</option>
                                    <option value="mpesa">
                                        Outgoing M-Pesa
                                    </option>
                                </select>
                                <InputError message={errors.payment_method} />
                            </div>
                            {form.data.payment_method === 'mpesa' && (
                                <div className="space-y-2">
                                    <Label htmlFor="payment_reference">
                                        Outgoing transaction code
                                    </Label>
                                    <Input
                                        id="payment_reference"
                                        value={form.data.payment_reference}
                                        onChange={(event) =>
                                            form.setData(
                                                'payment_reference',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        maxLength={100}
                                    />
                                    <InputError
                                        message={errors.payment_reference}
                                    />
                                </div>
                            )}
                        </>
                    )}
                </section>
                <section className="space-y-4 rounded-xl border bg-card p-5">
                    <div className="flex flex-wrap items-center gap-4">
                        <h2 className="font-semibold">
                            {debt
                                ? 'Outstanding debts'
                                : 'Counted / received items'}
                        </h2>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setCsvMode(!csvMode)}
                        >
                            {csvMode
                                ? 'Enter rows manually'
                                : 'Upload CSV instead'}
                        </Button>
                        <a
                            href={`/owner/inventory/template?kind=${kind}`}
                            className="text-sm underline"
                        >
                            Download CSV headers
                        </a>
                    </div>
                    {csvMode ? (
                        <div className="space-y-3">
                            <Label htmlFor="csv">
                                CSV file (up to 500 rows / 1 MB)
                            </Label>
                            <Input
                                id="csv"
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) =>
                                    form.setData(
                                        'csv',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                                required
                            />
                            <InputError message={errors.csv} />
                            <p className="text-sm text-muted-foreground">
                                {debt
                                    ? 'Use customer IDs from the directory, amounts in KES, and due dates as YYYY-MM-DD.'
                                    : 'Use existing SKUs and their exact unit labels. Leave unknown opening costs blank; delivery costs are required.'}
                            </p>
                            <p className="text-xs">
                                Headers:{' '}
                                {debt
                                    ? 'customer_id,amount,due_on'
                                    : 'sku,unit,quantity,unit_cost'}
                            </p>
                            {Object.entries(errors)
                                .filter(([key]) => key.startsWith('rows.'))
                                .map(([key, message]) => (
                                    <InputError
                                        key={key}
                                        message={`${key}: ${message}`}
                                    />
                                ))}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {form.data.rows.map((row, index) => (
                                <div
                                    key={index}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <div className="flex items-center justify-between">
                                        <h3 className="text-sm font-semibold">
                                            Row {index + 1}
                                        </h3>
                                        {form.data.rows.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() =>
                                                    form.setData(
                                                        'rows',
                                                        form.data.rows.filter(
                                                            (_, position) =>
                                                                position !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                            >
                                                Remove row
                                            </Button>
                                        )}
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        {debt ? (
                                            <>
                                                <div className="space-y-2 sm:col-span-2">
                                                    <Label
                                                        htmlFor={`customer-${index}`}
                                                    >
                                                        Existing customer
                                                    </Label>
                                                    <select
                                                        id={`customer-${index}`}
                                                        value={row.customer_id}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                customer_id:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        required
                                                        className="h-9 w-full rounded border bg-background px-3 text-sm"
                                                    >
                                                        <option value="">
                                                            Select customer
                                                        </option>
                                                        {customers.map(
                                                            (customer) => (
                                                                <option
                                                                    key={
                                                                        customer.id
                                                                    }
                                                                    value={
                                                                        customer.id
                                                                    }
                                                                >
                                                                    #
                                                                    {
                                                                        customer.id
                                                                    }{' '}
                                                                    {
                                                                        customer.name
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        customer.contact
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `rows.${index}.customer_id`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label
                                                        htmlFor={`amount-${index}`}
                                                    >
                                                        Outstanding amount (KES)
                                                    </Label>
                                                    <Input
                                                        id={`amount-${index}`}
                                                        value={row.amount}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                amount: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        required
                                                        inputMode="decimal"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `rows.${index}.amount`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label
                                                        htmlFor={`due-${index}`}
                                                    >
                                                        Promised due date
                                                    </Label>
                                                    <Input
                                                        id={`due-${index}`}
                                                        type="date"
                                                        value={row.due_on}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                due_on: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `rows.${index}.due_on`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                            </>
                                        ) : (
                                            <>
                                                <div className="space-y-2">
                                                    <Label
                                                        htmlFor={`sku-${index}`}
                                                    >
                                                        SKU / product
                                                    </Label>
                                                    <select
                                                        id={`sku-${index}`}
                                                        value={row.sku}
                                                        onChange={(event) => {
                                                            const product =
                                                                products.find(
                                                                    (item) =>
                                                                        item.sku ===
                                                                        event
                                                                            .target
                                                                            .value,
                                                                );
                                                            updateRow(index, {
                                                                sku: event
                                                                    .target
                                                                    .value,
                                                                unit:
                                                                    product?.unit_label ??
                                                                    '',
                                                            });
                                                        }}
                                                        required
                                                        className="h-9 w-full rounded border bg-background px-3 text-sm"
                                                    >
                                                        <option value="">
                                                            Select product
                                                        </option>
                                                        {products
                                                            .filter(
                                                                (product) =>
                                                                    !receipt ||
                                                                    product.is_active,
                                                            )
                                                            .map((product) => (
                                                                <option
                                                                    key={
                                                                        product.sku
                                                                    }
                                                                    value={
                                                                        product.sku
                                                                    }
                                                                >
                                                                    {
                                                                        product.sku
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        product.name
                                                                    }
                                                                </option>
                                                            ))}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `rows.${index}.sku`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label
                                                        htmlFor={`unit-${index}`}
                                                    >
                                                        Exact unit
                                                    </Label>
                                                    <Input
                                                        id={`unit-${index}`}
                                                        value={row.unit}
                                                        readOnly
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `rows.${index}.unit`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label
                                                        htmlFor={`quantity-${index}`}
                                                    >
                                                        Quantity
                                                    </Label>
                                                    <Input
                                                        id={`quantity-${index}`}
                                                        value={row.quantity}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                quantity:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        required
                                                        inputMode="decimal"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `rows.${index}.quantity`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label
                                                        htmlFor={`cost-${index}`}
                                                    >
                                                        Unit cost (KES)
                                                        {!receipt &&
                                                            ' · optional'}
                                                    </Label>
                                                    <Input
                                                        id={`cost-${index}`}
                                                        value={row.unit_cost}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                unit_cost:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        required={receipt}
                                                        inputMode="decimal"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `rows.${index}.unit_cost`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.data.rows.length >= 500}
                                onClick={() =>
                                    form.setData('rows', [
                                        ...form.data.rows,
                                        emptyRow(),
                                    ])
                                }
                            >
                                Add row
                            </Button>
                        </div>
                    )}
                </section>
                <Button disabled={form.processing || blocked}>
                    {form.processing
                        ? 'Preparing review…'
                        : 'Save draft and review'}
                </Button>
            </form>
        </div>
    );
}

CreateBatch.layout = {
    breadcrumbs: [
        { title: 'Stock and opening balances', href: '/owner/inventory' },
        { title: 'New draft', href: '#' },
    ],
};
