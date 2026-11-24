import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Settings = {
    shop_name: string | null;
    address: string | null;
    contact: string | null;
    mpesa_number: string | null;
    version: number;
};

export default function ShopSettings({
    settings,
    status,
}: {
    settings: Settings;
    status?: string;
}) {
    return (
        <div className="max-w-2xl space-y-6 p-6">
            <Head title="Shop settings" />
            <div>
                <h1 className="text-2xl font-semibold">Shop settings</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Enter the actual shop identity and manual M-Pesa receiving
                    number.
                </p>
            </div>
            {status && (
                <p role="status" className="rounded border p-4">
                    {status}
                </p>
            )}
            <Form
                key={settings.version}
                action="/owner/shop-settings"
                method="patch"
                className="space-y-5 rounded-xl border bg-card p-5"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="version"
                            value={settings.version}
                        />
                        {errors.version && (
                            <div role="alert">
                                <InputError message={errors.version} />
                                <Link
                                    href="/owner/shop-settings"
                                    className="text-sm underline"
                                >
                                    Reload settings
                                </Link>
                            </div>
                        )}
                        {(
                            [
                                ['shop_name', 'Shop name'],
                                ['address', 'Address (optional)'],
                                ['contact', 'Shop contact (optional)'],
                                [
                                    'mpesa_number',
                                    'Manual M-Pesa receiving number (optional)',
                                ],
                            ] as const
                        ).map(([field, label]) => (
                            <div key={field} className="space-y-2">
                                <Label htmlFor={field}>{label}</Label>
                                <Input
                                    id={field}
                                    name={field}
                                    defaultValue={settings[field] ?? ''}
                                    required={field === 'shop_name'}
                                    maxLength={
                                        field === 'address'
                                            ? 500
                                            : field === 'shop_name'
                                              ? 255
                                              : field === 'mpesa_number'
                                                ? 20
                                                : 100
                                    }
                                />
                                <InputError message={errors[field]} />
                            </div>
                        ))}
                        <p className="text-sm text-muted-foreground">
                            <Label htmlFor="mpesa_number_confirmation">
                                Confirm receiving number
                            </Label>
                            <Input
                                id="mpesa_number_confirmation"
                                name="mpesa_number_confirmation"
                                defaultValue={settings.mpesa_number ?? ''}
                                maxLength={20}
                            />
                            Receiving-number changes are audited and apply to
                            future manual payments. They do not configure
                            merchant payments.
                        </p>
                        <div className="space-y-2">
                            <Label htmlFor="reason">Reason for change</Label>
                            <Input
                                id="reason"
                                name="reason"
                                required
                                maxLength={1000}
                            />
                            <InputError message={errors.reason} />
                        </div>
                        <p className="text-sm">
                            Currency: KES · Shop timezone: Africa/Nairobi ·
                            Inventory costing: weighted average
                        </p>
                        <Button disabled={processing}>
                            Save shop settings
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

ShopSettings.layout = {
    breadcrumbs: [{ title: 'Shop settings', href: '/owner/shop-settings' }],
};
