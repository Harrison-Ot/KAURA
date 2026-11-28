import { Head, Link, usePage } from '@inertiajs/react';
import ListPagination from '@/components/list-pagination';
import { buttonVariants } from '@/components/ui/button';
import type { Auth } from '@/types';
import type { Paginated } from '@/types/catalogue';

type Count = {
    id: number;
    reference: string;
    status: string;
    counted_at: string;
    counted_by_name: string;
    reason: string;
};

export default function StockCounts({ counts }: { counts: Paginated<Count> }) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Stock counts" />
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {owner
                            ? 'Stock counts and adjustments'
                            : 'My stock counts'}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Count physical goods, review discrepancies, then let the
                        owner approve an adjustment.
                    </p>
                </div>
                <Link href="/stock-counts/create" className={buttonVariants()}>
                    Record a physical count
                </Link>
            </div>
            {counts.data.length === 0 && (
                <p className="rounded-xl border border-dashed bg-card p-6">
                    No counts recorded yet. Submitting a count leaves stock
                    unchanged until owner approval.
                </p>
            )}
            {counts.data.map((count) => (
                <article
                    key={count.id}
                    className="space-y-2 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link
                            href={`/stock-counts/${count.id}`}
                            className="font-semibold underline"
                        >
                            {count.reference}
                        </Link>
                        <span className="rounded-full bg-secondary px-3 py-1 text-sm font-medium text-secondary-foreground">
                            {count.status === 'pending'
                                ? 'Awaiting owner review'
                                : count.status === 'approved'
                                  ? 'Adjustment posted'
                                  : 'Rejected'}
                        </span>
                    </div>
                    <p className="text-sm">
                        {count.counted_by_name} ·{' '}
                        {new Date(count.counted_at).toLocaleString('en-KE', {
                            timeZone: 'Africa/Nairobi',
                        })}{' '}
                        Nairobi
                    </p>
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {count.reason}
                    </p>
                </article>
            ))}
            <ListPagination {...counts} />
        </div>
    );
}
StockCounts.layout = {
    breadcrumbs: [{ title: 'Stock counts', href: '/stock-counts' }],
};
