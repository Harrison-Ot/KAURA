import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Boxes,
    Check,
    ChartNoAxesCombined,
    ShieldCheck,
    Store,
    Wallet,
} from 'lucide-react';
const features = [
    {
        icon: Boxes,
        title: 'Know your stock.',
        text: 'Separate selling units, clear stock balances and a record of every movement.',
    },
    {
        icon: Wallet,
        title: 'Account for every shilling.',
        text: 'Cash, manual M-Pesa and customer debt, with the detail you need to follow up.',
    },
    {
        icon: ShieldCheck,
        title: 'Work as one team.',
        text: 'Individual accounts, owner approvals and a clear record of who did what.',
    },
];
export default function Welcome() {
    return (
        <div className="min-h-screen bg-[#FFFBFC] text-[#0B132B]">
            <Head title="Kaura Hardware" />
            <header className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-6 py-7 lg:px-12">
                <Link
                    href="/"
                    className="flex items-center gap-3 font-semibold"
                >
                    <span className="rounded-xl bg-[#143109] p-2.5 text-white">
                        <Store size={23} />
                    </span>
                    <span>
                        KAURA
                        <span className="block text-[10px] tracking-[0.23em] text-[#0B132B]/55">
                            HARDWARE WORKSPACE
                        </span>
                    </span>
                </Link>
                <Link
                    href="/login"
                    className="rounded-lg border border-[#0B132B]/20 px-5 py-2.5 text-sm font-semibold"
                >
                    Sign in ?
                </Link>
            </header>
            <main>
                <section className="mx-auto grid max-w-7xl items-center gap-12 px-6 pt-10 pb-20 lg:grid-cols-2 lg:px-12 lg:pt-20 lg:pb-28">
                    <div>
                        <p className="mb-6 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-[#127475]">
                            <span className="size-2 rounded-full bg-[#127475]" />
                            BUILT FOR YOUR SHOP DAY
                        </p>
                        <h1 className="text-5xl leading-[1.08] font-semibold tracking-tight sm:text-6xl xl:text-7xl">
                            Every item.
                            <br />
                            Every shilling.
                            <br />
                            <span className="text-[#127475]">
                                In clear view.
                            </span>
                        </h1>
                        <p className="mt-7 max-w-md text-base leading-8 text-[#0B132B]/65">
                            A connected workspace for the counter, the stockroom
                            and the owner. Keep your shop moving with records
                            you can follow.
                        </p>
                        <div className="mt-9 flex flex-wrap gap-3">
                            <Link
                                href="/login"
                                className="inline-flex items-center gap-7 rounded-xl bg-[#127475] px-6 py-4 font-semibold text-white shadow-lg shadow-[#127475]/15"
                            >
                                Enter workspace
                                <ArrowRight size={18} />
                            </Link>
                            <Link
                                href="/signup"
                                className="rounded-xl border border-[#0B132B]/20 px-6 py-4 font-medium"
                            >
                                Join the team
                            </Link>
                        </div>
                        <div className="mt-7 flex flex-wrap gap-5 text-xs text-[#0B132B]/60">
                            {[
                                'KES accounting',
                                'Cash & manual M-Pesa',
                                'Personal staff accounts',
                            ].map((text) => (
                                <span
                                    key={text}
                                    className="flex items-center gap-1"
                                >
                                    <Check
                                        size={14}
                                        className="text-[#127475]"
                                    />
                                    {text}
                                </span>
                            ))}
                        </div>
                    </div>
                    <div className="relative rounded-[2rem] bg-[#eaf0e8] p-5 sm:p-9">
                        <div className="overflow-hidden rounded-2xl border border-[#143109]/10 bg-white shadow-2xl shadow-[#143109]/10">
                            <div className="flex items-center justify-between border-b p-5">
                                <span className="flex items-center gap-2 text-sm font-semibold">
                                    <Store
                                        size={17}
                                        className="text-[#127475]"
                                    />
                                    Shop overview
                                </span>
                                <span className="rounded-full bg-[#143109]/5 px-2.5 py-1 text-[10px] font-semibold text-[#143109]">
                                    WORKSPACE PREVIEW
                                </span>
                            </div>
                            <div className="space-y-5 p-5 sm:p-7">
                                <p className="text-xs font-medium tracking-wider text-[#0B132B]/50">
                                    ONE PLACE TO KEEP TRACK
                                </p>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-xl bg-[#143109] p-4 text-white">
                                        <Wallet
                                            size={20}
                                            className="mb-6 text-[#9bd3b4]"
                                        />
                                        <p className="font-semibold">
                                            Every collection
                                        </p>
                                        <p className="mt-1 text-xs text-white/70">
                                            Cash, verified & pending
                                        </p>
                                    </div>
                                    <div className="rounded-xl bg-[#eef5f4] p-4">
                                        <Boxes
                                            size={20}
                                            className="mb-6 text-[#127475]"
                                        />
                                        <p className="font-semibold">
                                            Every product
                                        </p>
                                        <p className="mt-1 text-xs text-[#0B132B]/60">
                                            Stock, units & movements
                                        </p>
                                    </div>
                                </div>
                                <div className="rounded-xl border p-5">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-semibold">
                                            See the bigger picture
                                        </span>
                                        <ChartNoAxesCombined
                                            size={19}
                                            className="text-[#127475]"
                                        />
                                    </div>
                                    <div
                                        className="mt-5 flex h-24 items-end gap-3"
                                        aria-hidden="true"
                                    >
                                        {[35, 60, 43, 72, 55, 85, 100].map(
                                            (height, i) => (
                                                <span
                                                    key={i}
                                                    style={{
                                                        height: `${height}%`,
                                                    }}
                                                    className="flex-1 rounded-t-md bg-[#127475]/20 last:bg-[#127475]"
                                                />
                                            ),
                                        )}
                                    </div>
                                    <p className="mt-4 text-xs text-[#0B132B]/55">
                                        Sales, collections and stock reports in
                                        one workspace.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <span className="absolute -bottom-4 left-10 flex items-center gap-2 rounded-xl bg-[#0B132B] px-5 py-3 text-xs font-medium text-white shadow-lg">
                            <ShieldCheck size={16} className="text-[#9bd3b4]" />
                            Owner oversight. Team accountability.
                        </span>
                    </div>
                </section>
                <section className="border-y border-[#143109]/10 bg-[#f3f6f1]">
                    <div className="mx-auto grid max-w-7xl gap-10 px-6 py-14 md:grid-cols-3 lg:px-12">
                        {features.map(({ icon: Icon, title, text }) => (
                            <article key={title}>
                                <Icon
                                    size={27}
                                    className="mb-5 text-[#127475]"
                                />
                                <h2 className="text-lg font-semibold">
                                    {title}
                                </h2>
                                <p className="mt-3 text-sm leading-7 text-[#0B132B]/65">
                                    {text}
                                </p>
                            </article>
                        ))}
                    </div>
                </section>
            </main>
            <footer className="mx-auto flex max-w-7xl flex-wrap justify-between gap-3 px-6 py-7 text-xs text-[#0B132B]/55 lg:px-12">
                <span>Kaura Hardware ? Shop workspace</span>
                <span>Stock. Sales. People. In sync.</span>
            </footer>
        </div>
    );
}
