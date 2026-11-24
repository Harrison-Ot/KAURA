import { Head, Link, usePage } from '@inertiajs/react';
import ListPagination from '@/components/list-pagination';
import { kes } from '@/lib/catalogue';
import type { Auth } from '@/types';
import type { Approval } from '@/types/approvals';
import type { Paginated } from '@/types/catalogue';

export default function Approvals({
    requests,
}: {
    requests: Paginated<Approval>;
}) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    return (
        <div className="space-y-6 p-6">
            <Head title="Sale approvals" />
            <h1 className="text-2xl font-semibold">
                {owner ? 'Sale approval requests' : 'My approval requests'}
            </h1>
            <p className="text-sm text-muted-foreground">
                Discounts and credit require an approval for the exact saved
                cart.
            </p>
            <Link href="/sales/create" className="text-sm underline">
                Build a new cart
            </Link>
            {requests.data.length === 0 && (
                <p className="rounded border border-dashed p-6">
                    No requests yet. Request approval from checkout.
                </p>
            )}
            {requests.data.map((approval) => (
                <Link
                    key={approval.id}
                    href={`/sale-requests/${approval.id}`}
                    className="block space-y-2 rounded-xl border bg-card p-5 hover:bg-muted"
                >
                    <h2 className="font-semibold">
                        Request #{approval.id} · {approval.status}
                    </h2>
                    <p>
                        {approval.customer_snapshot?.name ?? 'Walk-in customer'}{' '}
                        · Total {kes(approval.total)}
                    </p>
                    <p className="text-sm">
                        Discount {kes(approval.discount)} · Credit{' '}
                        {kes(approval.credit)} · Deposit {kes(approval.deposit)}
                    </p>
                </Link>
            ))}
            <ListPagination {...requests} />
        </div>
    );
}

Approvals.layout = {
    breadcrumbs: [{ title: 'Sale approvals', href: '/sale-requests' }],
};
