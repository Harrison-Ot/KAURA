import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes, quantity } from '@/lib/catalogue';
import type { Category, Product } from '@/types/catalogue';

type History = {
    id: number;
    selling_price: string;
    purchase_cost: string | null;
    actor_name: string;
    reason: string;
    effective_at: string;
};
type Props = {
    product: Product | null;
    categories: Category[];
    history: History[];
};

export default function ProductForm({ product, categories, history }: Props) {
    const editing = product !== null;
    const fields = [
        {
            name: 'sku',
            label: 'SKU',
            value: product?.sku ?? '',
            max: 50,
            required: true,
            locked: editing,
        },
        {
            name: 'name',
            label: 'Product name',
            value: product?.name ?? '',
            max: 255,
            required: true,
        },
        {
            name: 'unit_label',
            label: 'Exact selling unit (piece, bag, kg…)',
            value: product?.unit_label ?? '',
            max: 30,
            required: true,
            locked: editing,
        },
        {
            name: 'selling_price',
            label: 'Selling price (KES)',
            value: product?.selling_price ?? '',
            max: 12,
            required: true,
            decimal: true,
        },
        {
            name: 'purchase_cost',
            label: 'Reference purchase cost per unit (KES)',
            value: product?.purchase_cost ?? '',
            max: 12,
            decimal: true,
        },
        {
            name: 'reorder_threshold',
            label: 'Reorder threshold',
            value: product
                ? quantity(
                      product.reorder_threshold,
                      product.quantity_precision,
                  )
                : '0',
            max: 13,
            required: true,
            decimal: true,
        },
        {
            name: 'barcode',
            label: 'Barcode (optional)',
            value: product?.barcode ?? '',
            max: 100,
        },
    ];
    return (
        <div className="max-w-4xl space-y-6 p-4 sm:p-6">
            <Head title={editing ? 'Edit product' : 'Add product'} />
            <div>
                <Link href="/products" className="text-sm underline">
                    Back to products
                </Link>
                <h1 className="mt-3 text-2xl font-semibold">
                    {editing ? 'Edit product' : 'Add product'}
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    {editing
                        ? 'SKU, selling unit and quantity precision identify this listing. Create a separate listing for another unit.'
                        : 'New listings start at zero stock. A bag and a kilogram need separate SKUs; no stock converts automatically.'}
                </p>
            </div>
            {categories.length === 0 && (
                <p role="status" className="rounded border p-4">
                    Add a category on the{' '}
                    <Link href="/products" className="underline">
                        products page
                    </Link>{' '}
                    before creating a listing.
                </p>
            )}
            <Form
                key={`${product?.id ?? 'new'}-${product?.version ?? 0}`}
                action={product ? `/products/${product.id}` : '/products'}
                method={editing ? 'patch' : 'post'}
                className="space-y-6 rounded-xl border bg-card p-5"
            >
                {({ errors, processing }) => (
                    <>
                        {product && (
                            <input
                                type="hidden"
                                name="version"
                                value={product.version}
                            />
                        )}
                        {errors.version && (
                            <div
                                role="alert"
                                className="rounded border border-destructive p-3"
                            >
                                <InputError message={errors.version} />
                                <Link
                                    href={`/products/${product?.id}/edit`}
                                    className="text-sm underline"
                                >
                                    Reload current listing
                                </Link>
                            </div>
                        )}
                        <div className="grid gap-5 sm:grid-cols-2">
                            {fields.map((field) => (
                                <div key={field.name} className="space-y-2">
                                    <Label htmlFor={field.name}>
                                        {field.label}
                                    </Label>
                                    <Input
                                        id={field.name}
                                        name={field.name}
                                        defaultValue={field.value}
                                        required={field.required}
                                        maxLength={field.max}
                                        readOnly={field.locked}
                                        inputMode={
                                            field.decimal ? 'decimal' : 'text'
                                        }
                                    />
                                    <InputError message={errors[field.name]} />
                                    {field.name === 'purchase_cost' && (
                                        <p className="text-xs text-muted-foreground">
                                            Leave blank if unknown. This
                                            reference does not change posted
                                            inventory value; opening counts and
                                            deliveries record their own costs.
                                        </p>
                                    )}
                                </div>
                            ))}
                            <div className="space-y-2">
                                <Label htmlFor="category_id">Category</Label>
                                <select
                                    id="category_id"
                                    name="category_id"
                                    defaultValue={product?.category_id ?? ''}
                                    required
                                    className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                >
                                    <option value="" disabled>
                                        Select a category
                                    </option>
                                    {categories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.category_id} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="quantity_precision">
                                    Quantity decimal places
                                </Label>
                                <select
                                    id="quantity_precision"
                                    name="quantity_precision"
                                    defaultValue={
                                        product?.quantity_precision ?? 0
                                    }
                                    disabled={editing}
                                    className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                >
                                    {[0, 1, 2, 3].map((precision) => (
                                        <option
                                            key={precision}
                                            value={precision}
                                        >
                                            {precision === 0
                                                ? 'Whole units only'
                                                : `${precision} decimal places`}
                                        </option>
                                    ))}
                                </select>
                                {product && (
                                    <input
                                        type="hidden"
                                        name="quantity_precision"
                                        value={product.quantity_precision}
                                    />
                                )}
                                <InputError
                                    message={errors.quantity_precision}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="is_active">Availability</Label>
                                <select
                                    id="is_active"
                                    name="is_active"
                                    defaultValue={
                                        product?.is_active === false ? '0' : '1'
                                    }
                                    className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                >
                                    <option value="1">Active listing</option>
                                    <option value="0">
                                        Inactive — unavailable for new sales
                                    </option>
                                </select>
                                <InputError message={errors.is_active} />
                            </div>
                        </div>
                        {editing && (
                            <div className="space-y-2">
                                <Label htmlFor="reason">
                                    Reason for change
                                </Label>
                                <Input
                                    id="reason"
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
                                I reviewed similar names and confirm this is a
                                separate product listing.
                            </label>
                            <InputError message={errors.confirm_duplicate} />
                        </div>
                        {errors.quantity_on_hand && (
                            <InputError message={errors.quantity_on_hand} />
                        )}
                        <Button
                            disabled={processing || categories.length === 0}
                        >
                            {processing
                                ? 'Saving…'
                                : editing
                                  ? 'Save changes'
                                  : 'Create product'}
                        </Button>
                    </>
                )}
            </Form>
            {editing && (
                <section className="space-y-3">
                    <h2 className="font-semibold">
                        Recent price and cost history
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Most recent 20 records. Changes take effect when saved;
                        earlier records stay unchanged.
                    </p>
                    {history.map((entry) => (
                        <article
                            key={entry.id}
                            className="space-y-1 rounded-lg border p-4 text-sm"
                        >
                            <p className="font-medium">
                                Selling price: {kes(entry.selling_price)} ·
                                Purchase cost:{' '}
                                {entry.purchase_cost === null
                                    ? 'Unknown'
                                    : kes(entry.purchase_cost)}
                            </p>
                            <p>
                                {entry.actor_name} ·{' '}
                                {new Date(entry.effective_at).toLocaleString(
                                    'en-KE',
                                    { timeZone: 'Africa/Nairobi' },
                                )}{' '}
                                Nairobi
                            </p>
                            <p>{entry.reason}</p>
                        </article>
                    ))}
                </section>
            )}
        </div>
    );
}

ProductForm.layout = {
    breadcrumbs: [
        { title: 'Products', href: '/products' },
        { title: 'Product listing', href: '#' },
    ],
};
