import { Form, Head, Link } from '@inertiajs/react';
import ListPagination from '@/components/list-pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { Paginated } from '@/types/catalogue';

type Movement = {
    id: number;
    kind: string;
    quantity_delta: string | number;
    balance_after: string | number;
    effective_on: string;
    label: string;
    sku: string;
    unit_label: string;
    batch_id: number | null;
    sale_id: number | null;
    count_id: number | null;
    reference: string;
    actor_name: string;
};

export default function StockLedger({
    movements,
    products,
    product_id,
}: {
    movements: Paginated<Movement>;
    products: { id: number; sku: string; name: string }[];
    product_id: string | number;
}) {
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Stock movements" />
            <div>
                <Link href="/owner/inventory" className="text-sm underline">
                    Back to stock and opening balances
                </Link>
                <h1 className="mt-3 text-2xl font-semibold">Stock movements</h1>
                <p className="text-sm text-muted-foreground">
                    Posted quantities, source batches and running balances.
                </p>
            </div>
            <Form
                action="/owner/inventory/ledger"
                method="get"
                className="flex flex-wrap items-end gap-3"
            >
                <div className="space-y-2">
                    <Label htmlFor="product_id">Product</Label>
                    <select
                        id="product_id"
                        name="product_id"
                        defaultValue={product_id}
                        className="h-9 w-full rounded border bg-background px-3 text-sm"
                    >
                        <option value="">All products</option>
                        {products.map((product) => (
                            <option key={product.id} value={product.id}>
                                {product.sku} · {product.name}
                            </option>
                        ))}
                    </select>
                </div>
                <Button>Filter</Button>
            </Form>
            {movements.data.length === 0 && (
                <p className="rounded-xl border border-dashed p-6">
                    No posted movements for this selection.
                </p>
            )}
            <div className="space-y-3">
                {movements.data.map((movement) => (
                    <article
                        key={movement.id}
                        className="space-y-2 rounded-xl border bg-card p-4"
                    >
                        <h2 className="font-semibold">
                            {movement.sku} · {movement.label}
                        </h2>
                        <p className="text-sm">
                            {movement.effective_on} ·{' '}
                            {movement.kind.replaceAll('_', ' ')} ·{' '}
                            {movement.actor_name}
                        </p>
                        <p>
                            Movement {movement.quantity_delta}{' '}
                            {movement.unit_label} · Balance{' '}
                            {movement.balance_after} {movement.unit_label}
                        </p>
                        <Link
                            href={
                                movement.count_id
                                    ? `/stock-counts/${movement.count_id}`
                                    : movement.sale_id
                                      ? `/sales/${movement.sale_id}`
                                      : `/owner/inventory/${movement.batch_id}`
                            }
                            className="text-xs break-all underline"
                        >
                            {movement.reference}
                        </Link>
                    </article>
                ))}
            </div>
            <ListPagination {...movements} />
        </div>
    );
}

StockLedger.layout = {
    breadcrumbs: [
        { title: 'Stock movements', href: '/owner/inventory/ledger' },
    ],
};
