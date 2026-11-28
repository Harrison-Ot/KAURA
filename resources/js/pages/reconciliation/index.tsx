import { Form, Head, usePage } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { minorMoney } from '@/lib/money';

type Handover = {
    id: number;
    declared_minor: string;
    received_minor: string | null;
    expected_minor: string;
    employee_note: string;
    owner_note: string | null;
    reviewed_at: string | null;
};
type Row = {
    employee: { id: number; name: string };
    expected_minor: string;
    stale: boolean;
    handover: Handover | null;
    payments: { method: string; status: string; amount_minor: string }[];
};
export default function Reconciliation({
    day,
    today,
    isOwner,
    rows,
}: {
    day: string;
    today: string;
    isOwner: boolean;
    rows: Row[];
}) {
    const userId = (usePage().props.auth as { user: { id: number } }).user.id;
    const inputClass = 'w-full rounded-lg border bg-background p-2.5 text-sm';
    return (
        <div className="space-y-7 p-4 sm:p-8">
            <Head title="My day & cash handover" />
            <header className="flex gap-4">
                <span className="rounded-xl bg-primary/10 p-3 text-primary">
                    <Landmark />
                </span>
                <div>
                    <p className="text-xs tracking-widest text-muted-foreground">
                        CLOSE THE DAY
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold">
                        {isOwner ? 'Cash reconciliation' : 'My day & handover'}
                    </h1>
                </div>
            </header>
            <p className="max-w-3xl text-sm leading-6 text-muted-foreground">
                Compare recorded cash activity with the money physically handed
                over. M-Pesa is shown separately. Later postings flag a reviewed
                day for follow-up.
            </p>
            <Form
                action="/my-day"
                method="get"
                className="flex flex-wrap items-end gap-3"
            >
                <label className="grid gap-2 text-sm">
                    Nairobi business day
                    <input
                        type="date"
                        required
                        max={today}
                        defaultValue={day}
                        name="day"
                        className={inputClass}
                    />
                </label>
                <Button>View day</Button>
            </Form>
            <div className="grid gap-5 xl:grid-cols-2">
                {rows.map((row) => (
                    <article
                        key={row.employee.id}
                        className="space-y-5 rounded-2xl border bg-card p-6"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold">
                                {row.employee.name}
                            </h2>
                            <span className="rounded-full bg-secondary px-3 py-1 text-xs font-medium">
                                {row.stale
                                    ? 'Changed since review'
                                    : row.handover?.reviewed_at
                                      ? 'Owner reviewed'
                                      : row.handover
                                        ? 'Awaiting owner'
                                        : 'Not submitted'}
                            </span>
                        </div>
                        <div className="rounded-xl bg-secondary p-4">
                            <p className="text-xs text-muted-foreground">
                                Net recorded cash movement
                            </p>
                            <p className="mt-1 text-2xl font-semibold">
                                {minorMoney(row.expected_minor)}
                            </p>
                            <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                Cash collected less cash outflows and refunds,
                                including money returned through corrections.
                                Negative means net cash supplied by the shop.
                            </p>
                        </div>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            {row.payments.map((payment) => (
                                <div key={payment.method + payment.status}>
                                    <dt className="text-xs text-muted-foreground">
                                        {payment.method.replaceAll('_', ' ')} ·{' '}
                                        {payment.status}
                                    </dt>
                                    <dd className="mt-1 text-sm font-semibold">
                                        {minorMoney(payment.amount_minor)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {row.handover ? (
                            <div className="space-y-3 border-t pt-4">
                                <p className="text-sm">
                                    Declared handover:{' '}
                                    <strong>
                                        {minorMoney(
                                            row.handover.declared_minor,
                                        )}
                                    </strong>
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {row.handover.employee_note}
                                </p>
                                {row.handover.received_minor !== null && (
                                    <>
                                        <p className="text-sm">
                                            Owner received:{' '}
                                            <strong>
                                                {minorMoney(
                                                    row.handover.received_minor,
                                                )}
                                            </strong>
                                        </p>
                                        <p className="text-sm font-semibold">
                                            Difference to current recorded cash:{' '}
                                            {minorMoney(
                                                String(
                                                    BigInt(
                                                        row.handover
                                                            .received_minor,
                                                    ) -
                                                        BigInt(
                                                            row.expected_minor,
                                                        ),
                                                ),
                                            )}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {row.handover.owner_note}
                                        </p>
                                    </>
                                )}
                                {row.stale && (
                                    <p
                                        role="status"
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        Activity changed after
                                        submission/review. Compare the original
                                        expected amount{' '}
                                        {minorMoney(
                                            row.handover.expected_minor,
                                        )}{' '}
                                        with current activity before closing the
                                        day.
                                    </p>
                                )}
                                {isOwner && !row.handover.reviewed_at && (
                                    <Form
                                        action={`/owner/cash-handovers/${row.handover.id}/review`}
                                        method="post"
                                        className="space-y-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                {Object.values(errors).map(
                                                    (error, i) => (
                                                        <p
                                                            key={i}
                                                            role="alert"
                                                            className="text-sm text-destructive"
                                                        >
                                                            {error}
                                                        </p>
                                                    ),
                                                )}
                                                <label className="grid gap-2 text-sm">
                                                    Actual cash received (KES)
                                                    <input
                                                        name="received"
                                                        required
                                                        inputMode="decimal"
                                                        className={inputClass}
                                                    />
                                                </label>
                                                <label className="grid gap-2 text-sm">
                                                    Owner reconciliation /
                                                    discrepancy notes
                                                    <textarea
                                                        name="note"
                                                        required
                                                        maxLength={2000}
                                                        className={inputClass}
                                                    />
                                                </label>
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input
                                                        required
                                                        name="confirm"
                                                        type="checkbox"
                                                        value="1"
                                                    />
                                                    I counted the cash received.
                                                </label>
                                                <Button disabled={processing}>
                                                    Record owner review
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </div>
                        ) : row.employee.id === userId ? (
                            <Form
                                key={day}
                                action="/my-day"
                                method="post"
                                className="space-y-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="day"
                                            value={day}
                                        />
                                        {Object.values(errors).map(
                                            (error, i) => (
                                                <p
                                                    key={i}
                                                    role="alert"
                                                    className="text-sm text-destructive"
                                                >
                                                    {error}
                                                </p>
                                            ),
                                        )}
                                        <label className="grid gap-2 text-sm">
                                            Cash handed over (KES)
                                            <input
                                                required
                                                name="amount"
                                                inputMode="decimal"
                                                className={inputClass}
                                            />
                                        </label>
                                        <label className="grid gap-2 text-sm">
                                            Handover note
                                            <textarea
                                                required
                                                name="note"
                                                maxLength={2000}
                                                className={inputClass}
                                            />
                                        </label>
                                        <Button disabled={processing}>
                                            Submit my handover
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                The employee submits their own handover.
                            </p>
                        )}
                    </article>
                ))}
            </div>
        </div>
    );
}
