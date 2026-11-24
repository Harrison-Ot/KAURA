import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function InitialPassword() {
    return (
        <>
            <Head title="Change initial password" />
            <p className="mb-6 text-sm text-muted-foreground">
                Choose a private password with at least 12 characters, upper and
                lowercase letters, a number and a symbol.
            </p>
            <Form
                action="/account/password"
                method="put"
                resetOnSuccess
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        {(
                            [
                                'current_password',
                                'password',
                                'password_confirmation',
                            ] as const
                        ).map((field) => (
                            <div key={field} className="space-y-2">
                                <Label htmlFor={field}>
                                    {field === 'current_password'
                                        ? 'Temporary password'
                                        : field === 'password'
                                          ? 'New password'
                                          : 'Confirm new password'}
                                </Label>
                                <Input
                                    id={field}
                                    name={field}
                                    type="password"
                                    required
                                    autoComplete={
                                        field === 'current_password'
                                            ? 'current-password'
                                            : 'new-password'
                                    }
                                />
                                <InputError message={errors[field]} />
                            </div>
                        ))}
                        <Button disabled={processing}>Save password</Button>
                    </>
                )}
            </Form>
            <Link
                href="/logout"
                method="post"
                as="button"
                className="mt-6 text-sm underline"
            >
                Log out
            </Link>
        </>
    );
}

InitialPassword.layout = {
    title: 'Secure your account',
    description: 'Replace your initial password before continuing.',
};
