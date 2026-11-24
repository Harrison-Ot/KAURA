import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { kes } from '@/lib/catalogue';
import type { FinancialReport } from '@/types/reports';

function MoneyCard({
    label,
    value,
    note,
}: {
    label: string;
    value: string | null;
    note?: string;
}) {
    return (
        <article className="space-y-2 rounded-xl border bg-card p-5">
            <h3 className="text-sm text-muted-foreground">{label}</h3>
            <p className="text-xl font-semibold">
                {value === null ? 'Incomplete costs' : kes(value)}
            </p>
            {note && <p className="text-xs text-muted-foreground">{note}</p>}
        </article>
    );
}

export default function Financial({ report }: { report: FinancialReport }) {
    const s = report.summary;
    const query = new URLSearchParams({
        from: report.from,
        to: report.to,
    }).toString();
    return (
        <div className="space-y-8 p-4 sm:p-6">
            <Head title="Financial report" />
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Financial report</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Sales, collections and money out · KES · Nairobi
                        business days
                    </p>
                </div>
                <a
                    href={`/owner/reports/financial/export?${query}`}
                    className="text-sm underline"
                >
                    Download report CSV
                </a>
            </div>
            <Form
                key={query}
                action="/owner/reports/financial"
                method="get"
                className="space-y-4 rounded-xl border bg-card p-5"
            >
                {({ errors }) => (
                    <>
                        {Object.entries(errors).map(([field, message]) => (
                            <InputError key={field} message={message} />
                        ))}
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="report-from">From</Label>
                                <Input
                                    id="report-from"
                                    name="from"
                                    type="date"
                                    defaultValue={report.from}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="report-to">Through</Label>
                                <Input
                                    id="report-to"
                                    name="to"
                                    type="date"
                                    defaultValue={report.to}
                                    required
                                />
                            </div>
                            <Button>View report</Button>
                            <Link
                                href="/owner/reports/financial"
                                className="pb-2 text-sm underline"
                            >
                                Today
                            </Link>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Select up to 366 inclusive days. Generated{' '}
                            {new Date(report.generated_at).toLocaleString(
                                'en-KE',
                                { timeZone: 'Africa/Nairobi' },
                            )}{' '}
                            Nairobi. Refresh to include new postings and
                            verification decisions.
                        </p>
                    </>
                )}
            </Form>
            {!report.opening_ready && (
                <p
                    role="alert"
                    className="rounded-xl border bg-secondary p-4 text-secondary-foreground"
                >
                    Opening balances have not been signed off. Draft imports are
                    excluded.
                </p>
            )}
            <section className="space-y-4">
                <div>
                    <h2 className="text-lg font-semibold">
                        Sales and estimated profit
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {s.sale_count} posted sales in the selected dates.
                        Opening debts and later repayments are excluded from
                        sales revenue.
                    </p>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <MoneyCard label="Gross sales" value={s.gross_sales} />
                    <MoneyCard label="Approved discounts" value={s.discounts} />
                    <MoneyCard label="Net sales" value={s.net_sales} />
                    <MoneyCard
                        label="Recorded sale costs"
                        value={s.known_sale_cost}
                        note={`${s.unknown_cost_sales} sales have incomplete costs; their costs are excluded here.`}
                    />
                    <MoneyCard
                        label="Gross margin estimate"
                        value={s.gross_margin_estimate}
                        note="Net sales less saved cost of goods sold."
                    />
                    <MoneyCard
                        label="Operating profit estimate"
                        value={s.operating_profit_estimate}
                        note="Gross margin less operating expenses. Withdrawals and stock purchases are excluded from this deduction."
                    />
                </div>
                {s.unknown_cost_sales > 0 && (
                    <p
                        role="status"
                        className="rounded-xl border bg-secondary p-4 text-sm text-secondary-foreground"
                    >
                        Profit estimates are unavailable because{' '}
                        {s.unknown_cost_sales} sales have unknown costs. Current
                        catalogue prices cannot fill historical cost gaps.
                    </p>
                )}
                <p className="text-xs text-muted-foreground">
                    Costs use carried weighted-average inventory value saved at
                    checkout. Estimates exclude tax calculations, stock-count
                    valuation gains/losses and unrecorded costs. Partial returns
                    and credit redemption are not yet implemented. Void refunds
                    are reported separately.
                </p>
            </section>
            <section className="space-y-4">
                <h2 className="text-lg font-semibold">
                    Collections by payment entry date
                </h2>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <MoneyCard
                        label="Confirmed collections"
                        value={s.confirmed_collections}
                    />
                    <MoneyCard
                        label="Confirmed cash"
                        value={s.cash_confirmed}
                        note="Amount retained after change, including cash instalments."
                    />
                    <MoneyCard
                        label="Verified M-Pesa"
                        value={s.mpesa_verified}
                    />
                    <MoneyCard
                        label="Recorded M-Pesa, all statuses"
                        value={s.mpesa_recorded}
                    />
                    <MoneyCard
                        label="Pending M-Pesa"
                        value={s.mpesa_pending}
                        note="Excluded from confirmed collections."
                    />
                    <MoneyCard
                        label="Disputed M-Pesa"
                        value={s.mpesa_disputed}
                        note="Excluded from confirmed collections."
                    />
                </div>
                <p className="text-sm text-muted-foreground">
                    Confirmed instalments included above:{' '}
                    {kes(s.confirmed_instalments)}. Status reflects owner
                    decisions as of this snapshot, even when verification
                    happened after the selected payment entry day. Instalments
                    are collections against debt and do not create new sales.
                </p>
            </section>
            <section className="space-y-4">
                <h2 className="text-lg font-semibold">
                    Money out and cash movement
                </h2>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <MoneyCard
                        label="Operating expenses"
                        value={s.operating_expenses}
                    />
                    <MoneyCard
                        label="Stock purchases"
                        value={s.stock_purchases}
                    />
                    <MoneyCard
                        label="Owner withdrawals"
                        value={s.owner_withdrawals}
                        note="Reduces funds; excluded from expenses and profit deductions."
                    />
                    <MoneyCard
                        label="Customer refunds"
                        value={s.refunds}
                        note="Refund payouts are separate from operating expenses."
                    />
                    <MoneyCard
                        label="Collections less refunds"
                        value={s.net_collections_after_refunds}
                    />
                    <MoneyCard label="Cash out" value={s.cash_out} />
                    <MoneyCard label="M-Pesa out" value={s.mpesa_out} />
                    <MoneyCard
                        label="Net cash movement"
                        value={s.net_cash_movement}
                        note="Confirmed cash collected less all recorded cash paid out."
                    />
                </div>
                <p className="text-sm text-muted-foreground">
                    Cash movement excludes opening cash and physical handovers.
                    It is not the available drawer balance or a completed cash
                    reconciliation.{' '}
                    <Link
                        href={`/owner/outflows?${query}`}
                        className="underline"
                    >
                        Inspect money-out entries
                    </Link>
                    .
                </p>
            </section>
            <section className="space-y-4">
                <h2 className="text-lg font-semibold">Activity by account</h2>
                <p className="text-sm text-muted-foreground">
                    Sales belong to the seller; collections belong to the person
                    recording the payment. Later owner verification does not
                    change the original collector. Cash movement here describes
                    recorded activity, not a physical cash handover.
                </p>
                {report.employees.length === 0 && (
                    <p className="rounded-xl border border-dashed bg-card p-5">
                        No posted activity in these dates.
                    </p>
                )}
                <div className="grid gap-4 lg:grid-cols-2">
                    {report.employees.map((employee) => (
                        <article
                            key={employee.id}
                            className="space-y-3 rounded-xl border bg-card p-5"
                        >
                            <h3 className="font-semibold">
                                {employee.name}
                                {!employee.active && ' · Inactive account'}
                            </h3>
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Sales ({employee.sale_count})
                                    </dt>
                                    <dd>{kes(employee.net_sales)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Cash confirmed
                                    </dt>
                                    <dd>{kes(employee.cash_confirmed)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        M-Pesa verified
                                    </dt>
                                    <dd>{kes(employee.mpesa_verified)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        M-Pesa pending
                                    </dt>
                                    <dd>{kes(employee.mpesa_pending)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        M-Pesa disputed
                                    </dt>
                                    <dd>{kes(employee.mpesa_disputed)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Cash out
                                    </dt>
                                    <dd>{kes(employee.cash_out)}</dd>
                                </div>
                                <div className="col-span-2">
                                    <dt className="text-muted-foreground">
                                        Net recorded cash movement
                                    </dt>
                                    <dd className="font-semibold">
                                        {kes(employee.net_cash_movement)}
                                    </dd>
                                </div>
                            </dl>
                        </article>
                    ))}
                </div>
            </section>
            <section className="space-y-4 rounded-xl border bg-secondary p-5 text-secondary-foreground">
                <h2 className="text-lg font-semibold">
                    Current debt and inventory snapshot
                </h2>
                <p className="text-sm">
                    These balances are current at generation time, across all
                    dates. They are not historical closing balances for the
                    selected period. Outstanding amounts include unverified
                    claims.
                </p>
                <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {[
                        [
                            'Voided-sale confirmed funds held, unresolved',
                            report.current.void_confirmed_funds_held,
                        ],
                        [
                            'Voided-sale pending claims',
                            report.current.void_pending_claims,
                        ],
                        [
                            'Retained customer credit liability',
                            report.current.retained_customer_credit,
                        ],
                        ['Outstanding total', report.current.outstanding],
                        ['Opening debt remaining', report.current.opening_debt],
                        [
                            'Credit sale balances, including pending deposits',
                            report.current.credit_sale_debt,
                        ],
                        [
                            'Full-payment verification exposure',
                            report.current.verification_exposure,
                        ],
                        [
                            'Unassigned customer balances',
                            report.current.unassigned_debt,
                        ],
                        [
                            'Past-due credit and opening debt',
                            report.current.overdue_credit_and_opening,
                        ],
                        [
                            'Known inventory value',
                            report.current.known_inventory_value,
                        ],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-sm">{label}</dt>
                            <dd className="font-semibold">{kes(value)}</dd>
                        </div>
                    ))}
                </dl>
                <p className="text-sm">
                    {report.current.unknown_cost_listings} listings have unknown
                    inventory valuation. Pending M-Pesa has not reduced
                    confirmed debt.
                </p>
            </section>
        </div>
    );
}
Financial.layout = {
    breadcrumbs: [
        { title: 'Financial report', href: '/owner/reports/financial' },
    ],
};
