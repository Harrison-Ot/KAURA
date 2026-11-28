<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Support\CatalogueValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?Customer $customer = null): Customer
    {
        Gate::forUser($actor)->authorize($customer ? 'update' : 'create', $customer ?? Customer::class);

        return DB::transaction(function () use ($actor, $data, $customer): Customer {
            $creating = $customer === null;
            $customer = $creating ? new Customer : Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if (! $creating && $customer->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'This customer changed in another session. Reload before editing again.']);
            }
            $nameKey = CatalogueValues::nameKey((string) $data['name']);
            $contactKey = CatalogueValues::contactKey((string) $data['contact']);
            if (preg_match('/[\p{L}\p{N}]/u', (string) $data['contact']) !== 1) {
                throw ValidationException::withMessages(['contact' => 'Enter a contact number or reference containing letters or digits.']);
            }
            $matches = Customer::query()->where(fn ($query) => $query->where('name_key', $nameKey)->orWhere('contact_key', $contactKey))
                ->when(! $creating, fn ($query) => $query->where('id', '!=', $customer->id))->limit(3)->get(['name', 'contact']);
            if ($matches->isNotEmpty() && empty($data['confirm_duplicate'])) {
                $labels = $matches->map(fn (Customer $match): string => $match->name.' ('.$match->contact.')')->implode(', ');
                throw ValidationException::withMessages(['confirm_duplicate' => 'Possible existing customer: '.$labels.'. Search the directory first, then confirm only if a separate customer is needed.']);
            }
            $before = $creating ? null : $customer->toArray();
            $customer->fill(['name' => $data['name'], 'name_key' => $nameKey, 'contact' => $data['contact'], 'contact_key' => $contactKey]);
            if ($creating) {
                $customer->setAttribute('created_by', $actor->id);
            } else {
                $customer->version++;
            }
            $customer->save();
            AccessAudit::record($creating ? 'customer.created' : 'customer.updated', $actor, null, $creating ? 'Customer added with zero credit limit.' : (string) $data['reason'], $before, $customer->toArray(), 'customer', $customer->id);

            return $customer;
        });
    }

    public function setCreditLimit(User $actor, Customer $customer, string $amount, int $version, string $reason): void
    {
        Gate::forUser($actor)->authorize('update', $customer);
        DB::transaction(function () use ($actor, $customer, $amount, $version, $reason): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->version !== $version) {
                throw ValidationException::withMessages(['version' => 'This customer changed in another session. Reload before setting the limit.']);
            }
            $before = $customer->only(['credit_limit_minor', 'version']);
            $customer->credit_limit_minor = CatalogueValues::minorUnits($amount);
            $customer->version++;
            $customer->save();
            AccessAudit::record('customer.credit_limit_changed', $actor, null, $reason, $before, $customer->only(['credit_limit_minor', 'version']), 'customer', $customer->id);
        });
    }
}
