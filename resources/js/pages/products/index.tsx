import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import ListPagination from '@/components/list-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes, quantity } from '@/lib/catalogue';
import type { Auth } from '@/types';
import type { Category, Paginated, Product } from '@/types/catalogue';

type Props = {
    products: Paginated<Product>;
    categories: Category[];
    filters: {
        q: string;
        category: number | '';
        status: string;
        low_stock: boolean;
    };
    status?: string;
};

export default function Products({
    products,
    categories,
    filters,
    status,
}: Props) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Products" />
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Products</h1>
                    <p className="text-sm text-muted-foreground">
                        Each SKU has its own selling unit and stock balance.
                    </p>
                </div>
                {owner && (
                    <Button asChild>
                        <Link href="/products/create">Add product</Link>
                    </Button>
                )}
            </div>
            {status && (
                <p role="status" className="rounded-lg border p-4">
                    {status}
                </p>
            )}
            <Form
                action="/products"
                method="get"
                className="grid items-end gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-5"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                placeholder="Name, SKU or barcode"
                                defaultValue={filters.q}
                                maxLength={100}
                            />
                            <InputError message={errors.q} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="category">Category</Label>
                            <select
                                id="category"
                                name="category"
                                defaultValue={filters.category}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">All categories</option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.category} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                defaultValue={filters.status}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="all">All listings</option>
                            </select>
                            <InputError message={errors.status} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="low_stock">Stock filter</Label>
                            <select
                                id="low_stock"
                                name="low_stock"
                                defaultValue={filters.low_stock ? '1' : '0'}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="0">All stock levels</option>
                                <option value="1">
                                    At or below reorder level
                                </option>
                            </select>
                            <InputError message={errors.low_stock} />
                        </div>
                        <div className="flex items-center gap-3">
                            <Button disabled={processing}>Search</Button>
                            <Link
                                href="/products"
                                className="text-sm underline"
                            >
                                Reset
                            </Link>
                        </div>
                    </>
                )}
            </Form>
            {products.data.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center">
                    <h2 className="font-semibold">No matching products</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Try another search or ask the owner to add the listing.
                    </p>
                </div>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {products.data.map((product) => (
                        <article
                            key={product.id}
                            className="space-y-4 rounded-xl border bg-card p-5"
                        >
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    {product.sku} · {product.category_name}
                                </p>
                                <h2 className="mt-1 text-lg font-semibold wrap-anywhere">
                                    {product.name}
                                </h2>
                                <p className="text-sm">
                                    Per {product.unit_label} ·{' '}
                                    {product.is_active ? 'Active' : 'Inactive'}
                                </p>
                            </div>
                            <div>
                                <p className="font-semibold">
                                    {kes(product.selling_price)}
                                </p>
                                {owner && (
                                    <div className="space-y-1 text-xs text-muted-foreground">
                                        <p>
                                            Reference purchase cost:{' '}
                                            {product.purchase_cost == null
                                                ? 'Unknown'
                                                : kes(product.purchase_cost)}
                                        </p>
                                        <p>
                                            Weighted-average cost:{' '}
                                            {product.average_cost == null
                                                ? 'Not available'
                                                : kes(product.average_cost)}
                                        </p>
                                        <p>
                                            Inventory value:{' '}
                                            {product.inventory_value == null
                                                ? 'Incomplete — missing stock costs'
                                                : kes(product.inventory_value)}
                                        </p>
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap justify-between gap-2 text-sm">
                                <span>
                                    Stock:{' '}
                                    {quantity(
                                        product.quantity_on_hand,
                                        product.quantity_precision,
                                    )}{' '}
                                    {product.unit_label}
                                </span>
                                <span>
                                    Reorder:{' '}
                                    {quantity(
                                        product.reorder_threshold,
                                        product.quantity_precision,
                                    )}
                                </span>
                            </div>
                            {product.is_active &&
                                Number(product.quantity_on_hand) <=
                                    Number(product.reorder_threshold) && (
                                    <p className="text-sm font-medium text-amber-700 dark:text-amber-400">
                                        Low stock · at or below reorder level
                                    </p>
                                )}
                            {product.barcode && (
                                <p className="text-xs text-muted-foreground">
                                    Barcode: {product.barcode}
                                </p>
                            )}
                            {owner && (
                                <Link
                                    href={`/products/${product.id}/edit`}
                                    className="inline-block text-sm font-medium underline"
                                >
                                    Edit listing
                                </Link>
                            )}
                        </article>
                    ))}
                </div>
            )}
            <ListPagination {...products} />
            {owner && (
                <section className="max-w-lg space-y-3 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Add category</h2>
                    <Form
                        action="/categories"
                        method="post"
                        resetOnSuccess
                        className="space-y-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Label htmlFor="category_name">
                                    Category name
                                </Label>
                                <Input
                                    id="category_name"
                                    name="name"
                                    maxLength={100}
                                    required
                                />
                                <InputError message={errors.name} />
                                <Button variant="outline" disabled={processing}>
                                    Save category
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            )}
        </div>
    );
}

Products.layout = { breadcrumbs: [{ title: 'Products', href: '/products' }] };
