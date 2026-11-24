import { Head, Link } from '@inertiajs/react';

type AuditEvent = {
    resource_type: string | null;
    resource_id: number | null;
    id: number;
    event: string;
    actor_name: string | null;
    subject_name: string | null;
    reason: string;
    created_at: string;
    before_state: Record<string, unknown> | null;
    after_state: Record<string, unknown> | null;
};
type Props = {
    events: {
        data: AuditEvent[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

export default function Audit({ events }: Props) {
    return (
        <div className="space-y-6 p-6">
            <Head title="Audit history" />
            <div>
                <h1 className="text-2xl font-semibold">Audit history</h1>
                <p className="text-sm text-muted-foreground">
                    Account, catalogue and customer changes. Times shown in
                    Nairobi.
                </p>
            </div>
            {events.data.length === 0 && <p>No events recorded yet.</p>}
            <div className="space-y-3">
                {events.data.map((event) => (
                    <article
                        key={event.id}
                        className="space-y-2 rounded-xl border bg-card p-4"
                    >
                        <div className="flex flex-wrap justify-between gap-2">
                            <h2 className="font-semibold">{event.event}</h2>
                            <time
                                className="text-sm"
                                dateTime={event.created_at}
                            >
                                {new Date(event.created_at).toLocaleString(
                                    'en-KE',
                                    { timeZone: 'Africa/Nairobi' },
                                )}
                            </time>
                        </div>
                        {event.resource_type && (
                            <p className="text-sm">
                                Record: {event.resource_type} #
                                {event.resource_id}
                            </p>
                        )}
                        <p className="text-sm">
                            Actor: {event.actor_name ?? 'Unauthenticated'} ·
                            Account: {event.subject_name ?? '—'}
                        </p>
                        <p className="text-sm wrap-anywhere">{event.reason}</p>
                        {(event.before_state || event.after_state) && (
                            <details className="text-sm">
                                <summary className="cursor-pointer">
                                    View recorded changes
                                </summary>
                                <pre className="mt-2 overflow-x-auto wrap-anywhere whitespace-pre-wrap">
                                    {JSON.stringify(
                                        {
                                            before: event.before_state,
                                            after: event.after_state,
                                        },
                                        null,
                                        2,
                                    )}
                                </pre>
                            </details>
                        )}
                    </article>
                ))}
            </div>
            <nav aria-label="Audit pagination" className="flex gap-4 text-sm">
                {events.prev_page_url && (
                    <Link href={events.prev_page_url}>Previous</Link>
                )}
                <span>
                    Page {events.current_page} of {events.last_page}
                </span>
                {events.next_page_url && (
                    <Link href={events.next_page_url}>Next</Link>
                )}
            </nav>
        </div>
    );
}

Audit.layout = {
    breadcrumbs: [{ title: 'Audit history', href: '/owner/audit' }],
};
