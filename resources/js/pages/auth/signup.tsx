import { Head, Link } from '@inertiajs/react';
import { ArrowRight, KeyRound, ShieldCheck, UserPlus } from 'lucide-react';
const steps = [
    {
        icon: UserPlus,
        title: 'Get your account',
        text: 'Give the owner your name and work email.',
    },
    {
        icon: KeyRound,
        title: 'Sign in securely',
        text: 'Use your temporary password, then choose your own.',
    },
    {
        icon: ShieldCheck,
        title: 'Verify your email',
        text: 'Follow the verification link to enter the workspace.',
    },
];
export default function Signup() {
    return (
        <>
            <Head title="Join the team" />
            <div className="space-y-6">
                <p className="text-sm leading-6 text-muted-foreground">
                    Your shop owner creates your individual employee account.
                    Ask them to add you from{' '}
                    <strong>Employees ? Create employee</strong>.
                </p>
                <ol className="space-y-5">
                    {steps.map(({ icon: Icon, title, text }) => (
                        <li key={title} className="flex gap-3">
                            <Icon
                                size={20}
                                className="mt-1 shrink-0 text-primary"
                            />
                            <div>
                                <p className="text-sm font-semibold">{title}</p>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    {text}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
                <Link
                    href="/login"
                    className="flex items-center justify-between rounded-lg bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground"
                >
                    I have an account
                    <ArrowRight size={16} />
                </Link>
                <p className="text-xs leading-5 text-muted-foreground">
                    Setting up this shop for the first time? The shop
                    administrator creates the initial owner account.
                </p>
            </div>
        </>
    );
}
Signup.layout = {
    title: 'Your place on the team.',
    description: 'Individual access. A clear record of your work.',
};
