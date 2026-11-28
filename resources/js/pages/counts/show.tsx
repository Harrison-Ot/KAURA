import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { Auth } from '@/types';

type Count = {
    id: number;
    reference: string;
    status: string;
    reason: string;
    counted_at: string;
    counted_by_name: string;
    decided_at: string | null;
    decided_by_name: string | null;
    decision_reason: string | null;
};
type Item = {
    id: number;
    name: string;
    sku: string;
    unit_label: string;
    system_quantity: string;
    counted_quantity: string;
    quantity_delta: string;
    stale: boolean;
    value_before?: string | null;
    value_after?: string | null;
};
export default function CountDetails({
    count,
    items,
}: {
    count: Count;
    items: Item[];
}) {
    const owner = usePage<{ auth: Auth }>().props.auth.user.role === 'owner';
    const stale =
        count.status === 'pending' && items.some((item) => item.stale);
    const date = (value: string) =>
        new Date(value).toLocaleString('en-KE', { timeZone: 'Africa/Nairobi' });
    return (
        <div className="max-w-4xl space-y-6 p-4 sm:p-6">
            <Head title="Stock count details" />
            <Link href="/stock-counts" className="text-sm underline">
                Back to counts
            </Link>
            <div className="flex flex-wrap justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Physical stock count
                    </h1>
                    <p className="mt-2 text-sm break-all text-muted-foreground">
                        {count.reference}
                    </p>
                </div>
                <Button variant="outline" onClick={() => router.reload()}>
                    Refresh status
                </Button>
            </div>
            <section className="space-y-2 rounded-xl border bg-card p-5">
                <p className="font-semibold">
                    {count.status === 'approved'
                        ? 'Approved — adjustment posted'
                        : count.status === 'pending'
                          ? 'Pending owner review — stock unchanged'
                          : 'Rejected — stock unchanged'}
                </p>
                <p>
                    Counted by {count.counted_by_name} ·{' '}
                    {date(count.counted_at)} Nairobi
                </p>
                <p className="wrap-anywhere">Reason: {count.reason}</p>
                {count.decided_at && (
                    <>
                        <p>
                            Decision by {count.decided_by_name} ·{' '}
                            {date(count.decided_at)} Nairobi
                        </p>
                        <p className="wrap-anywhere">{count.decision_reason}</p>
                    </>
                )}
            </section>
            {stale && (
                <p
                    role="alert"
                    className="rounded-xl border border-destructive bg-card p-4 text-destructive"
                >
                    Stock or product details changed after this count. The owner
                    must reject it and request a fresh physical count.
                </p>
            )}
            {items.map((item) => (
                <article
                    key={item.id}
                    className="space-y-3 rounded-xl border bg-card p-5"
                >
                    <h2 className="font-semibold">
                        {item.name}{' '}
                        <span className="text-sm font-normal text-muted-foreground">
                            {item.sku} · {item.unit_label}
                        </span>
                    </h2>
                    <dl className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                System at submission
                            </dt>
                            <dd className="font-medium">
                                {item.system_quantity}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Physically counted
                            </dt>
                            <dd className="font-medium">
                                {item.counted_quantity}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Discrepancy
                            </dt>
                            <dd className="font-semibold">
                                {item.quantity_delta} {item.unit_label}
                            </dd>
                        </div>
                    </dl>
                    {owner && (
                        <p className="text-sm text-muted-foreground">
                            Carried stock value before:{' '}
                            {item.value_before == null
                                ? 'Unknown'
                                : kes(item.value_before)}
                            {count.status === 'approved' &&
                                ` · After: ${item.value_after == null ? 'Unknown' : kes(item.value_after)}`}
                        </p>
                    )}
                </article>
            ))}
            {owner && count.status === 'pending' && (
                <section className="space-y-4 rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Owner decision</h2>
                    <p className="text-sm text-muted-foreground">
                        Approval posts each discrepancy once at today's Nairobi
                        date. Known stock keeps its carried average cost; found
                        stock with no known cost remains unknown. Reject stale
                        or incorrect counts and record a fresh one.
                    </p>
                    <Form
                        action={`/owner/stock-counts/${count.id}/decision`}
                        method="post"
                        className="space-y-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                {Object.entries(errors).map(
                                    ([field, message]) => (
                                        <InputError
                                            key={field}
                                            message={message}
                                        />
                                    ),
                                )}
                                <Label htmlFor="count-decision">Decision</Label>
                                <select
                                    id="count-decision"
                                    name="decision"
                                    defaultValue={
                                        stale ? 'rejected' : 'approved'
                                    }
                                    className="h-10 w-full rounded border border-input bg-background px-3"
                                >
                                    <option value="approved" disabled={stale}>
                                        Approve adjustment
                                    </option>
                                    <option value="rejected">
                                        Reject count
                                    </option>
                                </select>
                                <Label htmlFor="decision-reason">Reason</Label>
                                <Input
                                    id="decision-reason"
                                    name="reason"
                                    required
                                    maxLength={2000}
                                />
                                <label className="flex items-start gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        name="confirm"
                                        value="1"
                                        required
                                        className="mt-1"
                                    />
                                    I reviewed the physical count, discrepancies
                                    and stock valuation treatment.
                                </label>
                                <Button disabled={processing}>
                                    Save owner decision
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            )}
            {count.status === 'approved' && owner && (
                <Link
                    href="/owner/inventory/ledger"
                    className="text-sm underline"
                >
                    View linked stock movements
                </Link>
            )}
            {count.status === 'rejected' && (
                <Link href="/stock-counts/create" className="text-sm underline">
                    Record a fresh physical count
                </Link>
            )}
        </div>
    );
}
CountDetails.layout = {
    breadcrumbs: [
        { title: 'Stock counts', href: '/stock-counts' },
        { title: 'Count details', href: '#' },
    ],
};
