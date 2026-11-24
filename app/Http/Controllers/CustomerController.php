<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Support\CatalogueValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = Customer::query()->orderBy('name')->orderBy('id');
        if (($filters['q'] ?? '') !== '') {
            $needle = CatalogueValues::searchPattern($filters['q']);
            $contactKey = CatalogueValues::contactKey($filters['q']);
            $query->where(function ($query) use ($needle, $contactKey): void {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("LOWER(contact) LIKE ? ESCAPE '!'", [$needle]);
                if ($contactKey !== '') {
                    $query->orWhereRaw("contact_key LIKE ? ESCAPE '!'", [CatalogueValues::searchPattern($contactKey)]);
                }
            });
        }

        return Inertia::render('customers/index', [
            'customers' => $query->paginate(20)->withQueryString()->through(fn (Customer $customer): array => $this->present($customer, $request->user()->role === 'owner')),
            'filters' => ['q' => $filters['q'] ?? ''], 'status' => $request->session()->get('status'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/form', ['customer' => null]);
    }

    public function store(CustomerRequest $request, CustomerService $service): RedirectResponse
    {
        Gate::authorize('create', Customer::class);
        $service->save($request->user(), $request->validated());

        return to_route('customers.index')->with('status', 'Customer added. Credit starts at zero until the owner sets a limit.');
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('customers/form', ['customer' => $this->present($customer, true)]);
    }

    public function update(CustomerRequest $request, Customer $customer, CustomerService $service): RedirectResponse
    {
        Gate::authorize('update', $customer);
        $service->save($request->user(), $request->validated(), $customer);

        return to_route('customers.index')->with('status', 'Customer details updated.');
    }

    public function creditLimit(Request $request, Customer $customer, CustomerService $service): RedirectResponse
    {
        Gate::authorize('update', $customer);
        $data = $request->validate([
            'credit_limit' => ['required', 'regex:'.CatalogueValues::MONEY_RULE],
            'version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'],
        ]);
        $service->setCreditLimit($request->user(), $customer, (string) $data['credit_limit'], (int) $data['version'], $data['reason']);

        return to_route('customers.index')->with('status', 'Credit limit updated. Individual credit sales still need owner approval.');
    }

    /** @return array<string, mixed> */
    private function present(Customer $customer, bool $owner): array
    {
        $data = $customer->only(['id', 'name', 'contact', 'version']);
        if ($owner) {
            $data['credit_limit'] = CatalogueValues::money($customer->credit_limit_minor);
        }

        return $data;
    }
}
