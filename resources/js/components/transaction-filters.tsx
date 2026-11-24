import { Form, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TransactionFilters } from '@/types/sales';

export default function TransactionFilterForm({
    action,
    filters,
    employees,
}: {
    action: string;
    filters: TransactionFilters;
    employees: { id: number; name: string }[];
}) {
    return (
        <Form
            action={action}
            method="get"
            className="flex flex-wrap items-end gap-3 rounded-xl border p-4"
        >
            {({ errors, processing }) => (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="from">From (Nairobi day)</Label>
                        <Input
                            id="from"
                            name="from"
                            type="date"
                            defaultValue={filters.from}
                        />
                        <InputError message={errors.from} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            name="to"
                            type="date"
                            defaultValue={filters.to}
                        />
                        <InputError message={errors.to} />
                    </div>
                    {employees.length > 0 && (
                        <div className="space-y-2">
                            <Label htmlFor="employee_id">Employee</Label>
                            <select
                                id="employee_id"
                                name="employee_id"
                                defaultValue={filters.employee_id}
                                className="h-9 w-full rounded border bg-background px-3 text-sm"
                            >
                                <option value="">All employees</option>
                                {employees.map((employee) => (
                                    <option
                                        key={employee.id}
                                        value={employee.id}
                                    >
                                        {employee.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.employee_id} />
                        </div>
                    )}
                    {filters.status !== undefined && (
                        <div className="space-y-2">
                            <Label htmlFor="status">Verification status</Label>
                            <select
                                id="status"
                                name="status"
                                defaultValue={filters.status}
                                className="h-9 w-full rounded border bg-background px-3 text-sm"
                            >
                                <option value="all">All statuses</option>
                                <option value="pending">
                                    Pending verification
                                </option>
                                <option value="verified">Confirmed</option>
                                <option value="disputed">Disputed</option>
                            </select>
                            <InputError message={errors.status} />
                        </div>
                    )}
                    <Button disabled={processing}>Filter</Button>
                    <Link href={action} className="py-2 text-sm underline">
                        Reset
                    </Link>
                </>
            )}
        </Form>
    );
}
