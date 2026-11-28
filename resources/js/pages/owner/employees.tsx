import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Employee = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    must_change_password: boolean;
};

export default function Employees({
    employees,
    status,
}: {
    employees: Employee[];
    status?: string;
}) {
    return (
        <div className="space-y-8 p-6">
            <Head title="Employees" />
            <div>
                <h1 className="text-2xl font-semibold">Employees</h1>
                <p className="text-sm text-muted-foreground">
                    Manage counter access. Deactivation preserves activity
                    history.
                </p>
            </div>
            {status && (
                <p role="status" className="rounded border p-4">
                    {status}
                </p>
            )}
            <section className="max-w-xl space-y-4 rounded-xl border bg-card p-5">
                <h2 className="font-semibold">Create employee</h2>
                <p className="text-sm text-muted-foreground">
                    Share the temporary password privately. The employee must
                    replace it at first sign-in, then verify their email.
                </p>
                <Form
                    action="/owner/employees"
                    method="post"
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {(
                                [
                                    'name',
                                    'email',
                                    'password',
                                    'password_confirmation',
                                ] as const
                            ).map((field) => (
                                <div key={field} className="space-y-2">
                                    <Label htmlFor={field}>
                                        {field === 'name'
                                            ? 'Full name'
                                            : field === 'email'
                                              ? 'Email address'
                                              : field === 'password'
                                                ? 'Temporary password'
                                                : 'Confirm temporary password'}
                                    </Label>
                                    <Input
                                        id={field}
                                        name={field}
                                        required
                                        type={
                                            field.includes('password')
                                                ? 'password'
                                                : field === 'email'
                                                  ? 'email'
                                                  : 'text'
                                        }
                                        autoComplete={
                                            field.includes('password')
                                                ? 'new-password'
                                                : 'off'
                                        }
                                    />
                                    <InputError message={errors[field]} />
                                </div>
                            ))}
                            <p className="text-xs text-muted-foreground">
                                12+ characters, upper/lowercase letters, a
                                number and a symbol.
                            </p>
                            <Button disabled={processing}>
                                Create employee
                            </Button>
                        </>
                    )}
                </Form>
            </section>
            <section className="space-y-4">
                <h2 className="font-semibold">Employee accounts</h2>
                {employees.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No employee accounts yet.
                    </p>
                )}
                <div className="grid gap-4 lg:grid-cols-2">
                    {employees.map((employee) => (
                        <article
                            key={employee.id}
                            className="space-y-4 rounded-xl border bg-card p-5"
                        >
                            <div>
                                <h3 className="font-semibold">
                                    {employee.name}
                                </h3>
                                <p className="text-sm break-all">
                                    {employee.email}
                                </p>
                                <p className="mt-2 text-sm">
                                    {employee.is_active ? 'Active' : 'Inactive'}
                                    {employee.must_change_password
                                        ? ' · Initial password change required'
                                        : ''}
                                </p>
                            </div>
                            <Form
                                action={`/owner/employees/${employee.id}`}
                                method="patch"
                                resetOnSuccess
                                className="space-y-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="is_active"
                                            value={
                                                employee.is_active ? '0' : '1'
                                            }
                                        />
                                        <Label
                                            htmlFor={`reason-${employee.id}`}
                                        >
                                            Reason for access change
                                        </Label>
                                        <Input
                                            id={`reason-${employee.id}`}
                                            name="reason"
                                            required
                                            maxLength={1000}
                                        />
                                        <InputError
                                            message={
                                                errors.reason ??
                                                errors.is_active
                                            }
                                        />
                                        <Button
                                            variant={
                                                employee.is_active
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                            disabled={processing}
                                        >
                                            {employee.is_active
                                                ? 'Deactivate and revoke sessions'
                                                : 'Reactivate employee'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </article>
                    ))}
                </div>
            </section>
        </div>
    );
}

Employees.layout = {
    breadcrumbs: [{ title: 'Employees', href: '/owner/employees' }],
};
