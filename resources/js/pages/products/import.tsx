import { FileUp } from 'lucide-react';
import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Import = {
    id: number;
    source_note: string;
    posted_at: string | null;
    rows: string;
};
export default function CatalogueImport({
    submissionKey,
    headers,
    draft,
    recent,
}: {
    submissionKey: string;
    headers: string;
    draft: Import | null;
    recent: Omit<Import, 'rows'>[];
}) {
    const rows = draft
        ? (JSON.parse(draft.rows) as Record<string, string>[])
        : [];
    return (
        <div className="space-y-7 p-4 sm:p-8">
            <Head title="Import catalogue" />
            <header className="flex items-center gap-4">
                <FileUp className="text-primary" size={30} />
                <div>
                    <p className="text-xs tracking-widest text-muted-foreground">
                        OPENING PREPARATION
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold">
                        Import product listings
                    </h1>
                </div>
            </header>
            <p className="max-w-3xl text-sm leading-6 text-muted-foreground">
                Upload up to 500 listings, review every row, then confirm. New
                listings start with zero stock. Existing SKUs and barcodes are
                protected. Import opening quantities separately after review.
            </p>
            <Form
                action="/owner/catalogue-import"
                method="post"
                encType="multipart/form-data"
                className="max-w-2xl space-y-4 rounded-2xl border bg-card p-6"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="submission_key"
                            value={submissionKey}
                        />
                        {Object.values(errors).map((error, i) => (
                            <p
                                key={i}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        ))}
                        <p className="text-xs text-muted-foreground">
                            UTF-8 CSV headers, in this order:
                        </p>
                        <code className="block overflow-x-auto rounded-lg bg-muted p-3 text-xs">
                            {headers}
                        </code>
                        <p className="text-xs leading-5 text-muted-foreground">
                            Precision is 0–3 decimal places. Leave unknown
                            purchase costs and unused barcodes blank. New
                            category names are created on posting.
                        </p>
                        <label className="grid gap-2 text-sm">
                            CSV file
                            <input
                                required
                                type="file"
                                name="csv"
                                accept=".csv,text/csv"
                                className="rounded-lg border p-3"
                            />
                        </label>
                        <label className="grid gap-2 text-sm">
                            Source note
                            <input
                                required
                                name="source_note"
                                maxLength={2000}
                                className="rounded-lg border bg-background p-3"
                            />
                        </label>
                        <Button disabled={processing}>
                            Validate and preview
                        </Button>
                    </>
                )}
            </Form>
            {draft && (
                <section className="space-y-4">
                    <h2 className="text-xl font-semibold">
                        Import #{draft.id} ·{' '}
                        {draft.posted_at ? 'Posted' : 'Awaiting review'}
                    </h2>
                    <p className="text-sm">
                        {draft.source_note} · {rows.length} listings ·{' '}
                        {rows.filter((row) => !row.purchase_cost).length}{' '}
                        unknown costs
                    </p>
                    <div className="max-h-[500px] overflow-auto rounded-xl border">
                        <table className="w-full text-left text-xs">
                            <thead className="sticky top-0 bg-muted">
                                <tr>
                                    {headers.split(',').map((header) => (
                                        <th key={header} className="p-3">
                                            {header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, i) => (
                                    <tr key={i} className="border-t">
                                        {headers.split(',').map((header) => (
                                            <td key={header} className="p-3">
                                                {row[header] || '—'}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {!draft.posted_at && (
                        <Form
                            action={`/owner/catalogue-import/${draft.id}/post`}
                            method="post"
                            className="space-y-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {Object.values(errors).map((error, i) => (
                                        <p
                                            key={i}
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {error}
                                        </p>
                                    ))}
                                    <label className="flex items-start gap-3 text-sm">
                                        <input
                                            required
                                            type="checkbox"
                                            name="confirm"
                                            value="1"
                                        />
                                        I reviewed all rows, similar names,
                                        separate selling units and unknown
                                        costs.
                                    </label>
                                    <Button disabled={processing}>
                                        Post reviewed listings
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </section>
            )}
            <section className="space-y-3">
                <h2 className="font-semibold">Recent imports</h2>
                {recent.map((item) => (
                    <Link
                        key={item.id}
                        href={`/owner/catalogue-import?import=${item.id}`}
                        className="block rounded-lg border p-3 text-sm"
                    >
                        #{item.id} · {item.source_note} ·{' '}
                        {item.posted_at ? 'Posted' : 'Preview only'}
                    </Link>
                ))}
            </section>
        </div>
    );
}
