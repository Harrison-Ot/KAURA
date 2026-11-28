import { Head, Link } from '@inertiajs/react';
import { kes } from '@/lib/catalogue';
import { outflowLabels } from '@/types/outflows';
import type { Outflow } from '@/types/outflows';

export default function OutflowDetails({ outflow }: { outflow: Outflow }) {
    return (
        <div className="max-w-3xl space-y-6 p-4 sm:p-6">
            <Head title={outflow.reference} />
            <Link href="/owner/outflows" className="text-sm underline">
                Back to money out
            </Link>
            <div>
                <h1 className="text-2xl font-semibold">
                    {outflowLabels[outflow.kind]}
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    {outflow.reference} · Posted
                </p>
            </div>
            {outflow.kind !== 'refund' && (
                <Link
                    href={`/owner/operations/create?outflow_id=${outflow.id}`}
                    className="inline-flex rounded-lg border px-4 py-2 text-sm font-semibold text-primary"
                >
                    Record returned funds / linked reversal
                </Link>
            )}
            <section className="space-y-3 rounded-xl border bg-card p-5">
                <p className="text-2xl font-semibold">{kes(outflow.amount)}</p>
                <p>Business date: {outflow.effective_on} · Nairobi</p>
                <p>
                    Method:{' '}
                    {outflow.payment_method === 'cash' ? 'Cash' : 'M-Pesa'}
                </p>
                {outflow.payment_reference && (
                    <p>Transaction code: {outflow.payment_reference}</p>
                )}
                <p className="wrap-anywhere">Recipient: {outflow.recipient}</p>
                {outflow.category && (
                    <p>Expense category: {outflow.category}</p>
                )}
                <p className="wrap-anywhere">Reason: {outflow.reason}</p>
                <p>Recorded by {outflow.actor_name}</p>
                <p className="text-sm text-muted-foreground">
                    {new Date(outflow.created_at).toLocaleString('en-KE', {
                        timeZone: 'Africa/Nairobi',
                    })}{' '}
                    Nairobi
                </p>
            </section>
            <p className="rounded-xl border bg-secondary p-4 text-sm text-secondary-foreground">
                {outflow.kind === 'refund'
                    ? 'This refund is linked to voided-sale funds. It reduces funds and is kept separate from operating expenses.'
                    : outflow.kind === 'owner_withdrawal'
                      ? 'This withdrawal reduces shop funds and is excluded from operating expenses and profit deductions.'
                      : outflow.kind === 'stock_purchase'
                        ? 'This payment is linked to a supplier delivery. Inventory purchases are separate from operating expenses.'
                        : 'This entry is an operating cost. No stock or customer balance was changed.'}{' '}
                Posted entries retain their original amount, method and
                attribution. Corrections require a separate correction workflow.
            </p>
            {outflow.batch_id && (
                <Link
                    href={`/owner/inventory/${outflow.batch_id}`}
                    className="text-sm underline"
                >
                    View supplier delivery {outflow.batch_reference}
                </Link>
            )}
            {outflow.void_sale_id && (
                <Link
                    href={`/sales/${outflow.void_sale_id}`}
                    className="text-sm underline"
                >
                    View refunded voided sale
                </Link>
            )}
        </div>
    );
}
OutflowDetails.layout = {
    breadcrumbs: [
        { title: 'Money out', href: '/owner/outflows' },
        { title: 'Entry details', href: '#' },
    ],
};
