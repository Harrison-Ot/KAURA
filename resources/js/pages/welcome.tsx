import { Head, Link } from '@inertiajs/react';

export default function Welcome() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-background p-6 text-foreground">
            <Head title="Kaura Hardware" />
            <div className="max-w-lg space-y-6 rounded-2xl border bg-card p-8 shadow-sm">
                <p className="text-sm font-semibold text-primary">
                    KAURA HARDWARE
                </p>
                <h1 className="text-3xl font-semibold">Your shop workspace</h1>
                <p>Sign in with the account provided by the owner.</p>
                <Link
                    href="/login"
                    className="inline-block rounded-lg bg-primary px-6 py-3 font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Sign in
                </Link>
            </div>
        </main>
    );
}
