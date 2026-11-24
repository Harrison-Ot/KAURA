import { Link } from '@inertiajs/react';

type Props = {
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function ListPagination({
    current_page,
    last_page,
    total,
    prev_page_url,
    next_page_url,
}: Props) {
    return (
        <nav
            aria-label="Results pagination"
            className="flex flex-wrap items-center gap-4 text-sm"
        >
            <span>
                {total} results · Page {current_page} of {last_page}
            </span>
            {prev_page_url && (
                <Link className="underline" href={prev_page_url}>
                    Previous
                </Link>
            )}
            {next_page_url && (
                <Link className="underline" href={next_page_url}>
                    Next
                </Link>
            )}
        </nav>
    );
}
