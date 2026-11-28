import { Form, Head, Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import {
    ArrowDownToLine,
    ArrowUpRight,
    BarChart3,
    CalendarDays,
    CircleAlert,
    Package,
    Printer,
    Search,
    Users,
    Wallet,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { kes } from '@/lib/catalogue';
import { minorMoney } from '@/lib/money';
import type { FinancialReport } from '@/types/reports';

type Product = {
    id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    quantity: string;
    net_minor: string;
    margin_minor: string | null;
    stock: string;
    reorder: string;
    value_minor: string | null;
    low: boolean;
    active: boolean;
};
type Debt = {
    id: number;
    customer_id: number | null;
    name: string | null;
    contact: string | null;
    origin: string;
    sale_id: number | null;
    originated_on: string;
    due_on: string;
    remaining_amount_minor: string;
};
type Day = {
    day: string;
    net_sales: string;
    collections: string;
    pending: string;
    money_out: string;
};
type Detail = {
    damaged: {
        id: number;
        sale_id: number;
        business_date: string;
        sku: string;
        name: string;
        unit_label: string;
        quantity: string;
        cost_minor: string | null;
        reason: string;
    }[];
    daily: Day[];
    products: Product[];
    debts: Debt[];
    aging: Record<string, string>;
    expenses: { category: string; amount_minor: string }[];
    handovers: {
        id: number;
        name: string;
        business_date: string;
        declared_minor: string;
        received_minor: string | null;
        difference_minor: string | null;
        stale: boolean;
        owner_note: string | null;
    }[];
    alerts: {
        pending_payments: number;
        pending_counts: number;
        pending_approvals: number;
        low_stock: number;
    };
};
const tabs = [
    'Overview',
    'Products & stock',
    'Customer debt',
    'Team & cash',
    'Financial detail',
] as const;
type Tab = (typeof tabs)[number];
function Metric({
    label,
    value,
    note,
    dark = false,
}: {
    label: string;
    value: string | null;
    note?: string;
    dark?: boolean;
}) {
    return (
        <article
            className={`rounded-2xl border p-5 ${dark ? 'border-[#143109] bg-[#143109] text-white' : 'bg-card'}`}
        >
            <p
                className={`text-xs font-medium ${dark ? 'text-white/70' : 'text-muted-foreground'}`}
            >
                {label}
            </p>
            <p className="mt-3 text-2xl font-semibold tracking-tight tabular-nums">
                {value === null ? 'Incomplete costs' : kes(value)}
            </p>
            {note && (
                <p
                    className={`mt-3 text-xs leading-5 ${dark ? 'text-white/65' : 'text-muted-foreground'}`}
                >
                    {note}
                </p>
            )}
        </article>
    );
}
function DataTable({
    headers,
    rows,
    empty = 'No matching records.',
}: {
    headers: string[];
    rows: ReactNode[][];
    empty?: string;
}) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-left text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        {headers.map((header) => (
                            <th
                                key={header}
                                className="p-4 text-xs font-medium whitespace-nowrap text-muted-foreground"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-t hover:bg-muted/20">
                            {row.map((cell, j) => (
                                <td
                                    key={j}
                                    className="p-4 align-top tabular-nums"
                                >
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
            {rows.length === 0 && (
                <p className="p-10 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            )}
        </div>
    );
}
function Trend({ days }: { days: Day[] }) {
    const values = days.flatMap((day) => [
        Number(day.net_sales),
        Number(day.collections),
    ]);
    const high = Math.max(1, ...values);
    const low = Math.min(0, ...values);
    const range = high - low;
    const y = (value: number) => 170 - ((value - low) / range) * 140;
    const path = (key: 'net_sales' | 'collections') =>
        days
            .map(
                (day, i) =>
                    `${i === 0 ? 'M' : 'L'}${days.length === 1 ? 350 : 35 + (i / (days.length - 1)) * 630},${y(Number(day[key]))}`,
            )
            .join(' ');
    return (
        <div className="rounded-2xl border bg-card p-5 sm:p-7">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-semibold">Sales & collections</h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Daily activity in the selected Nairobi dates
                    </p>
                </div>
                <div className="flex gap-4 text-xs">
                    <span className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-[#127475]" />
                        Net sales
                    </span>
                    <span className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-[#0B132B] dark:bg-slate-300" />
                        Confirmed collections
                    </span>
                </div>
            </div>
            <svg
                viewBox="0 0 700 205"
                role="img"
                aria-label="Daily sales and confirmed collections trend. Exact daily values are in the table below."
                className="mt-6 w-full"
            >
                {[0, 0.5, 1].map((ratio) => (
                    <line
                        key={ratio}
                        x1="35"
                        x2="665"
                        y1={30 + ratio * 140}
                        y2={30 + ratio * 140}
                        stroke="currentColor"
                        opacity="0.1"
                    />
                ))}
                <line
                    x1="35"
                    x2="665"
                    y1={y(0)}
                    y2={y(0)}
                    stroke="currentColor"
                    opacity="0.2"
                />
                <path
                    d={path('net_sales')}
                    fill="none"
                    stroke="#127475"
                    strokeWidth="3"
                    strokeLinejoin="round"
                />
                <path
                    d={path('collections')}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeDasharray="5 4"
                    strokeLinejoin="round"
                />
                {days.length === 1 && (
                    <>
                        <circle
                            cx="350"
                            cy={y(Number(days[0].net_sales))}
                            r="4"
                            fill="#127475"
                        />
                        <circle
                            cx="350"
                            cy={y(Number(days[0].collections))}
                            r="3"
                            fill="currentColor"
                        />
                    </>
                )}
                {days
                    .filter(
                        (_, i) =>
                            i === 0 ||
                            i === days.length - 1 ||
                            (days.length > 7 &&
                                i % Math.ceil(days.length / 5) === 0),
                    )
                    .map((day) => (
                        <text
                            key={day.day}
                            x={
                                days.length === 1
                                    ? 350
                                    : 35 +
                                      (days.indexOf(day) / (days.length - 1)) *
                                          630
                            }
                            y="198"
                            textAnchor="middle"
                            fill="currentColor"
                            opacity="0.6"
                            fontSize="10"
                        >
                            {day.day.slice(5)}
                        </text>
                    ))}
            </svg>
            <p className="text-xs text-muted-foreground">
                Collections include old-debt repayments. Verification status
                reflects decisions at report generation time.
            </p>
        </div>
    );
}
export default function Financial({
    report,
    detail,
}: {
    report: FinancialReport;
    detail: Detail;
}) {
    const [tab, setTab] = useState<Tab>('Overview');
    const [search, setSearch] = useState('');
    const [lowOnly, setLowOnly] = useState(false);
    const [overdueOnly, setOverdueOnly] = useState(false);
    const [page, setPage] = useState(1);
    const [employee, setEmployee] = useState('');
    const s = report.summary;
    const query = new URLSearchParams({
        from: report.from,
        to: report.to,
    }).toString();
    const exportLink = (dataset: string) =>
        `/owner/reports/export?dataset=${dataset}&${query}`;
    const products = detail.products.filter(
        (product) =>
            `${product.name} ${product.sku} ${product.category}`
                .toLowerCase()
                .includes(search.toLowerCase()) &&
            (!lowOnly || (product.active && product.low)),
    );
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Africa/Nairobi',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
    const debts = detail.debts.filter(
        (debt) =>
            `${debt.name ?? ''} ${debt.contact ?? ''} ${debt.id}`
                .toLowerCase()
                .includes(search.toLowerCase()) &&
            (!overdueOnly || debt.due_on < today),
    );
    const download = (label: string, url: string) => (
        <a
            href={url}
            className="inline-flex items-center gap-2 rounded-lg border bg-card px-4 py-2.5 text-xs font-semibold hover:bg-muted"
        >
            <ArrowDownToLine size={14} />
            {label}
        </a>
    );
    const pager = (count: number) => (
        <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
            <span>
                {count} results ? Page{' '}
                {Math.min(page, Math.max(1, Math.ceil(count / 20)))} of{' '}
                {Math.max(1, Math.ceil(count / 20))}
            </span>
            <div className="flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                >
                    Previous
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={page * 20 >= count}
                    onClick={() => setPage(page + 1)}
                >
                    Next
                </Button>
            </div>
        </div>
    );
    return (
        <div className="space-y-7 p-4 sm:p-7 lg:p-9 print:p-0">
            <Head title="Reports & insights" />
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.18em] text-primary">
                        <BarChart3 size={15} />
                        BUSINESS INTELLIGENCE
                    </p>
                    <h1 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                        Reports & insights
                    </h1>
                    <p className="mt-3 text-sm text-muted-foreground">
                        A clear view of your shop, from the counter to the
                        accounts.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2 print:hidden">
                    {download(
                        'Financial CSV',
                        `/owner/reports/financial/export?${query}`,
                    )}
                    <Button variant="outline" onClick={() => window.print()}>
                        <Printer size={15} />
                        Print view
                    </Button>
                </div>
            </header>
            <Form
                key={query}
                action="/owner/reports/financial"
                method="get"
                className="flex flex-wrap items-end gap-4 rounded-2xl border bg-card p-5 print:hidden"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="mr-auto">
                            <p className="flex items-center gap-2 text-sm font-semibold">
                                <CalendarDays
                                    size={17}
                                    className="text-primary"
                                />
                                Reporting period
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                KES ? Africa/Nairobi ? Up to 366 days
                            </p>
                        </div>
                        {[
                            ['from', 'From', report.from],
                            ['to', 'Through', report.to],
                        ].map(([name, label, value]) => (
                            <label
                                key={name}
                                className="grid gap-2 text-xs text-muted-foreground"
                            >
                                {label}
                                <input
                                    required
                                    type="date"
                                    name={name}
                                    defaultValue={value}
                                    className="rounded-lg border bg-background p-2.5 text-sm text-foreground"
                                />
                            </label>
                        ))}
                        <Button disabled={processing}>Update report</Button>
                        {Object.values(errors).map((error) => (
                            <p
                                role="alert"
                                key={error}
                                className="w-full text-sm text-destructive"
                            >
                                {error}
                            </p>
                        ))}
                    </>
                )}
            </Form>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Metric
                    dark
                    label="Net sales"
                    value={s.net_sales}
                    note={`${s.sale_count} posted sales ? approved discounts and returns deducted`}
                />
                <Metric
                    label="Confirmed collections"
                    value={s.confirmed_collections}
                    note="Cash + verified M-Pesa, including instalments"
                />
                <Metric
                    label="Operating profit estimate"
                    value={s.operating_profit_estimate}
                    note="Recorded costs and expenses; withdrawals excluded"
                />
                <Metric
                    label="Customer debt ? current"
                    value={report.current.outstanding}
                    note="Current balance, including unresolved payment claims"
                />
            </div>
            <nav
                aria-label="Report sections"
                className="flex gap-1 overflow-x-auto border-b print:hidden"
            >
                {tabs.map((item) => (
                    <button
                        type="button"
                        key={item}
                        onClick={() => {
                            setTab(item);
                            setSearch('');
                            setPage(1);
                        }}
                        className={`shrink-0 border-b-2 px-4 py-3 text-sm font-medium ${tab === item ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
                    >
                        {item}
                    </button>
                ))}
            </nav>
            {tab === 'Overview' && (
                <div className="space-y-6">
                    <div className="grid gap-5 xl:grid-cols-[1.65fr_1fr]">
                        <Trend days={detail.daily} />
                        <section className="rounded-2xl border bg-card p-6">
                            <h2 className="font-semibold">
                                Needs your attention
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Live queues across all dates
                            </p>
                            <div className="mt-5 space-y-3">
                                {[
                                    [
                                        Wallet,
                                        'M-Pesa awaiting verification',
                                        detail.alerts.pending_payments,
                                        '/payments?status=pending',
                                    ],
                                    [
                                        Package,
                                        'Products at reorder threshold',
                                        detail.alerts.low_stock,
                                        '/products?low_stock=1',
                                    ],
                                    [
                                        CircleAlert,
                                        'Stock counts awaiting review',
                                        detail.alerts.pending_counts,
                                        '/stock-counts',
                                    ],
                                    [
                                        Users,
                                        'Sale requests awaiting approval',
                                        detail.alerts.pending_approvals,
                                        '/sale-requests',
                                    ],
                                ].map(([Icon, label, count, href]) => {
                                    const Symbol = Icon as typeof Wallet;
                                    return (
                                        <Link
                                            key={String(label)}
                                            href={String(href)}
                                            className="flex items-center gap-3 rounded-xl border p-3 hover:bg-muted/40"
                                        >
                                            <Symbol
                                                size={17}
                                                className="shrink-0 text-primary"
                                            />
                                            <span className="flex-1 text-xs">
                                                {String(label)}
                                            </span>
                                            <strong>{Number(count)}</strong>
                                            <ArrowUpRight size={13} />
                                        </Link>
                                    );
                                })}
                            </div>
                            <p className="mt-5 text-xs leading-5 text-muted-foreground">
                                {report.opening_ready
                                    ? 'Opening balances signed off.'
                                    : 'Opening sign-off is still required.'}{' '}
                                {report.current.unknown_cost_listings} listings
                                have unknown inventory value.
                            </p>
                        </section>
                    </div>
                    <section className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="font-semibold">Daily activity</h2>
                            {download('Daily CSV', exportLink('daily'))}
                        </div>
                        <DataTable
                            headers={[
                                'Nairobi day',
                                'Net sales',
                                'Confirmed collections',
                                'Pending M-Pesa',
                                'Net money out',
                            ]}
                            rows={detail.daily.map((day) => [
                                day.day,
                                minorMoney(day.net_sales),
                                minorMoney(day.collections),
                                minorMoney(day.pending),
                                minorMoney(day.money_out),
                            ])}
                        />
                    </section>
                </div>
            )}
            {tab === 'Products & stock' && (
                <section className="space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Product performance & inventory
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Sales and margins use the selected dates. Stock
                                and valuation are current.
                            </p>
                        </div>
                        {download('All products CSV', exportLink('products'))}
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <label className="flex min-w-60 items-center gap-2 rounded-lg border px-3">
                            <Search
                                size={15}
                                className="text-muted-foreground"
                            />
                            <input
                                aria-label="Search products"
                                placeholder="Search name, SKU or category"
                                value={search}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setPage(1);
                                }}
                                className="w-full bg-transparent py-3 text-sm outline-none"
                            />
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={lowOnly}
                                onChange={(e) => {
                                    setLowOnly(e.target.checked);
                                    setPage(1);
                                }}
                            />
                            Low stock only
                        </label>
                    </div>
                    <DataTable
                        headers={[
                            'Product',
                            'Net quantity sold',
                            'Net sales',
                            'Margin estimate',
                            'Current stock',
                            'Known stock value',
                            'Status',
                        ]}
                        rows={products
                            .slice((page - 1) * 20, page * 20)
                            .map((product) => [
                                <Link
                                    href={`/products/${product.id}/edit`}
                                    className="font-medium underline"
                                >
                                    {product.name}
                                    <span className="mt-1 block text-xs font-normal text-muted-foreground">
                                        {product.sku} ? {product.category}
                                    </span>
                                </Link>,
                                `${product.quantity} ${product.unit}`,
                                minorMoney(product.net_minor),
                                product.margin_minor === null
                                    ? 'Incomplete cost'
                                    : minorMoney(product.margin_minor),
                                `${product.stock} ${product.unit}`,
                                product.value_minor === null
                                    ? 'Unknown'
                                    : minorMoney(product.value_minor),
                                <span className="rounded-full bg-secondary px-2.5 py-1 text-xs">
                                    {!product.active
                                        ? 'Inactive'
                                        : product.low
                                          ? 'Reorder'
                                          : 'In stock'}
                                </span>,
                            ])}
                    />
                    {pager(products.length)}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="font-semibold">
                            Damaged goods received ? current history
                        </h3>
                        {download('Damaged goods CSV', exportLink('damaged'))}
                    </div>
                    <DataTable
                        headers={[
                            'Received',
                            'Product',
                            'Quantity outside sellable stock',
                            'Original cost',
                            'Reason',
                        ]}
                        rows={detail.damaged.map((item) => [
                            item.business_date,
                            `${item.sku} ? ${item.name}`,
                            `${item.quantity} ${item.unit_label}`,
                            item.cost_minor === null
                                ? 'Unknown'
                                : minorMoney(item.cost_minor),
                            item.reason,
                        ])}
                    />
                    <p className="text-xs text-muted-foreground">
                        Quantities are kept in each listing?s own unit. Damaged
                        returns never increase sellable stock. Margin retains
                        the cost of damaged goods.
                    </p>
                </section>
            )}
            {tab === 'Customer debt' && (
                <section className="space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Debt aging & collection priorities
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Current outstanding balances by promised date,
                                across all dates.
                            </p>
                        </div>
                        {download('All debt CSV', exportLink('debts'))}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        {Object.entries(detail.aging).map(([bucket, value]) => (
                            <div
                                key={bucket}
                                className="rounded-xl border bg-card p-4"
                            >
                                <p className="text-xs text-muted-foreground">
                                    {bucket === 'not_due'
                                        ? 'Not overdue'
                                        : bucket === 'over_90'
                                          ? 'Over 90 days'
                                          : bucket.replace('_', '?') + ' days'}
                                </p>
                                <p className="mt-3 font-semibold">
                                    {minorMoney(value)}
                                </p>
                            </div>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <input
                            aria-label="Search customer debts"
                            placeholder="Search customer, contact or debt ID"
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                setPage(1);
                            }}
                            className="min-w-64 rounded-lg border bg-background p-3 text-sm"
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={overdueOnly}
                                onChange={(e) => {
                                    setOverdueOnly(e.target.checked);
                                    setPage(1);
                                }}
                            />
                            Overdue only
                        </label>
                    </div>
                    <DataTable
                        headers={[
                            'Customer',
                            'Source',
                            'Promised date',
                            'Outstanding',
                            'Review',
                        ]}
                        rows={debts
                            .slice((page - 1) * 20, page * 20)
                            .map((debt) => [
                                <span className="font-medium">
                                    {debt.name ?? 'Unassigned customer'}
                                    <span className="mt-1 block text-xs font-normal text-muted-foreground">
                                        {debt.contact}
                                    </span>
                                </span>,
                                debt.origin.replaceAll('_', ' '),
                                debt.due_on,
                                minorMoney(debt.remaining_amount_minor),
                                debt.customer_id ? (
                                    <Link
                                        href={`/owner/customers/${debt.customer_id}/statement`}
                                        className="text-primary underline"
                                    >
                                        Open statement
                                    </Link>
                                ) : debt.sale_id ? (
                                    <Link
                                        href={`/sales/${debt.sale_id}`}
                                        className="underline"
                                    >
                                        Review sale
                                    </Link>
                                ) : (
                                    'Owner review required'
                                ),
                            ])}
                    />
                    {pager(debts.length)}
                    <div className="grid gap-3 md:grid-cols-3">
                        <Metric
                            label="Opening debt remaining"
                            value={report.current.opening_debt}
                        />
                        <Metric
                            label="Retained customer credit liability"
                            value={report.current.retained_customer_credit}
                        />
                        <Metric
                            label="Unassigned balances"
                            value={report.current.unassigned_debt}
                        />
                    </div>
                </section>
            )}
            {tab === 'Team & cash' && (
                <section className="space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Team activity & cash accountability
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Sales follow the seller. Collections follow the
                                collector.
                            </p>
                        </div>
                        <Link
                            href="/my-day"
                            className="inline-flex items-center gap-2 text-sm font-medium text-primary underline"
                        >
                            Review daily handovers
                            <ArrowUpRight size={15} />
                        </Link>
                    </div>
                    <label className="flex items-center gap-3 text-sm">
                        Account
                        <select
                            className="rounded-lg border bg-background p-2"
                            value={employee}
                            onChange={(e) => setEmployee(e.target.value)}
                        >
                            <option value="">All accounts</option>
                            {report.employees.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <DataTable
                        headers={[
                            'Account',
                            'Sales',
                            'Net sales',
                            'Cash collected',
                            'Verified M-Pesa',
                            'Pending',
                            'Disputed',
                            'Net cash movement',
                        ]}
                        rows={report.employees
                            .filter(
                                (account) =>
                                    !employee ||
                                    String(account.id) === employee,
                            )
                            .map((account) => [
                                <span className="font-medium">
                                    {account.name}
                                    {!account.active && (
                                        <span className="block text-xs text-muted-foreground">
                                            Inactive account
                                        </span>
                                    )}
                                </span>,
                                account.sale_count,
                                kes(account.net_sales),
                                kes(account.cash_confirmed),
                                kes(account.mpesa_verified),
                                kes(account.mpesa_pending),
                                kes(account.mpesa_disputed),
                                kes(account.net_cash_movement),
                            ])}
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="font-semibold">
                            Physical cash handovers
                        </h3>
                        {download('Handovers CSV', exportLink('handovers'))}
                    </div>
                    <DataTable
                        headers={[
                            'Day',
                            'Employee',
                            'Declared',
                            'Received by owner',
                            'Difference to current ledger',
                            'Review',
                        ]}
                        rows={detail.handovers.map((handover) => [
                            handover.business_date,
                            handover.name,
                            minorMoney(handover.declared_minor),
                            handover.received_minor === null
                                ? 'Awaiting review'
                                : minorMoney(handover.received_minor),
                            handover.difference_minor === null
                                ? '?'
                                : minorMoney(handover.difference_minor),
                            handover.stale
                                ? 'Activity changed after review'
                                : (handover.owner_note ?? 'Pending'),
                        ])}
                    />
                    <p className="text-xs text-muted-foreground">
                        No handover creates revenue. Later postings are flagged
                        when they change a reviewed day?s expected cash. Opening
                        floats are not included in net cash movement.
                    </p>
                </section>
            )}
            {tab === 'Financial detail' && (
                <section className="space-y-6">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Financial breakdown
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Revenue, collections and money out are distinct
                            measures.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {[
                            ['Gross sales', s.gross_sales],
                            ['Approved discounts', s.discounts],
                            ['Returns', s.returns],
                            ['Recorded cost of goods sold', s.known_sale_cost],
                            ['Gross margin estimate', s.gross_margin_estimate],
                            ['Operating expenses', s.operating_expenses],
                            [
                                'Stock purchases, net corrections',
                                s.stock_purchases,
                            ],
                            [
                                'Owner withdrawals, net corrections',
                                s.owner_withdrawals,
                            ],
                            ['Customer refunds', s.refunds],
                            ['Cash confirmed', s.cash_confirmed],
                            ['M-Pesa verified', s.mpesa_verified],
                            ['M-Pesa pending', s.mpesa_pending],
                            ['M-Pesa disputed', s.mpesa_disputed],
                            ['Confirmed instalments', s.confirmed_instalments],
                            ['Net cash movement', s.net_cash_movement],
                            ['Debt write-offs', s.debt_write_offs],
                            ['Retained-credit offsets', s.credit_offsets],
                            [
                                'Collections less refunds',
                                s.net_collections_after_refunds,
                            ],
                        ].map(([label, value]) => (
                            <Metric
                                key={label ?? ''}
                                label={label ?? ''}
                                value={value}
                            />
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-3">
                        {download(
                            'Correction history CSV',
                            exportLink('operations'),
                        )}
                        {download('Stock variance CSV', exportLink('variance'))}
                        <Link
                            href={`/owner/outflows?${query}`}
                            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2.5 text-xs font-semibold"
                        >
                            Inspect money out
                            <ArrowUpRight size={14} />
                        </Link>
                    </div>
                    <p className="rounded-xl bg-secondary p-5 text-sm leading-7">
                        Weighted-average costs are saved on sale lines. Returns
                        reduce revenue on the return date; resellable returns
                        restore their original allocated cost. Damaged return
                        costs remain charged. Profit excludes tax, stock-count
                        valuation changes, unrecorded expenses and write-offs
                        (shown separately). {s.unknown_cost_sales} sales or
                        returned cost entries have incomplete historical costs.
                    </p>
                </section>
            )}
            <footer className="border-t pt-5 text-xs leading-6 text-muted-foreground">
                Generated{' '}
                {new Date(report.generated_at).toLocaleString('en-KE', {
                    timeZone: 'Africa/Nairobi',
                })}{' '}
                Nairobi ? Activity: {report.from} through {report.to} ? Debt,
                inventory and review queues are current snapshots. Exports
                include all records in their stated scope; local table search
                and account selectors change the on-screen view only.
            </footer>
        </div>
    );
}
Financial.layout = {
    breadcrumbs: [
        { title: 'Reports & insights', href: '/owner/reports/financial' },
    ],
};
