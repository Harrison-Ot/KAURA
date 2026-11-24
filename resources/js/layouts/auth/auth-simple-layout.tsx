import { Link } from '@inertiajs/react';
import { ArrowLeft, Boxes, ShieldCheck, Store, Wallet } from 'lucide-react';
import type { AuthLayoutProps } from '@/types';
const pillars = [
    { icon: Boxes, text: 'Stock' },
    { icon: Wallet, text: 'Collections' },
    { icon: ShieldCheck, text: 'Accountability' },
];
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="grid min-h-svh bg-background text-foreground lg:grid-cols-[1fr_1.1fr]">
            <aside className="relative hidden flex-col justify-between overflow-hidden bg-[#143109] p-12 text-white lg:flex xl:p-16">
                <Link
                    href="/"
                    className="flex items-center gap-3 font-semibold"
                >
                    <Store size={25} />
                    KAURA HARDWARE
                </Link>
                <div>
                    <p className="mb-5 text-xs tracking-[0.2em] text-[#a5d6b5]">
                        YOUR SHOP. CONNECTED.
                    </p>
                    <h2 className="max-w-lg text-5xl leading-tight font-semibold tracking-tight">
                        Good business starts with a clear picture.
                    </h2>
                    <p className="mt-6 max-w-md leading-8 text-white/70">
                        Stock on the shelves. Sales at the counter. Money
                        accounted for. Pick up right where your day needs you.
                    </p>
                    <div className="mt-10 grid grid-cols-3 gap-3 border-t border-white/20 pt-7">
                        {pillars.map(({ icon: Icon, text }) => (
                            <div key={text} className="space-y-3">
                                <Icon size={23} className="text-[#a5d6b5]" />
                                <p className="text-xs text-white/75">{text}</p>
                            </div>
                        ))}
                    </div>
                </div>
                <p className="text-xs text-white/55">
                    One shop. One connected team.
                </p>
            </aside>
            <main className="flex flex-col px-6 py-7 sm:px-12">
                <Link
                    href="/"
                    className="flex w-fit items-center gap-2 text-sm text-muted-foreground hover:underline"
                >
                    <ArrowLeft size={15} />
                    Back to home
                </Link>
                <div className="mx-auto my-auto w-full max-w-md py-12">
                    <div className="mb-8">
                        <span className="mb-7 inline-flex rounded-xl bg-primary/5 p-3 text-primary">
                            <Store size={26} />
                        </span>
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        <p className="mt-3 text-sm leading-6 text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <div className="rounded-2xl border bg-card p-6 shadow-sm sm:p-8">
                        {children}
                    </div>
                    <p className="mt-6 text-center text-xs text-muted-foreground">
                        Your account keeps your work attributed to you.
                    </p>
                </div>
                <p className="text-center text-xs text-muted-foreground">
                    Kaura Hardware ? Africa/Nairobi
                </p>
            </main>
        </div>
    );
}
