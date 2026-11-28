<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\AccessAudit;
use App\Services\CatalogueService;
use App\Support\CatalogueValues;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])], 'low_stock' => ['nullable', 'boolean'],
        ]);
        $owner = $request->user()->role === 'owner';
        $status = $filters['status'] ?? 'active';
        $query = Product::query()->with('category:id,name')->orderBy('name')->orderBy('id');
        if (($filters['q'] ?? '') !== '') {
            $needle = CatalogueValues::searchPattern($filters['q']);
            $query->where(fn ($query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("LOWER(sku) LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("LOWER(barcode) LIKE ? ESCAPE '!'", [$needle]));
        }
        if (! empty($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }
        if ($status !== 'all') {
            $query->where('is_active', $status === 'active');
        }
        if (! empty($filters['low_stock'])) {
            $query->whereColumn('quantity_on_hand', '<=', 'reorder_threshold')->where('is_active', true);
        }

        return Inertia::render('products/index', [
            'products' => $query->paginate(20)->withQueryString()->through(fn (Product $product): array => $this->present($product, $owner)),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'filters' => ['q' => $filters['q'] ?? '', 'category' => $filters['category'] ?? '', 'status' => $status, 'low_stock' => (bool) ($filters['low_stock'] ?? false)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('products/form', ['product' => null, 'categories' => Category::query()->orderBy('name')->get(['id', 'name']), 'history' => []]);
    }

    public function edit(Product $product): Response
    {
        Gate::authorize('update', $product);

        return Inertia::render('products/form', [
            'product' => $this->present($product, true),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'history' => DB::table('product_price_history')->join('users', 'users.id', '=', 'product_price_history.actor_id')->where('product_id', $product->id)
                ->orderByDesc('product_price_history.id')->limit(20)->get(['product_price_history.id', 'selling_price_minor', 'purchase_cost_minor', 'reason', 'effective_at', 'users.name as actor_name'])
                ->map(fn (object $row): array => ['id' => $row->id, 'selling_price' => CatalogueValues::money((int) $row->selling_price_minor), 'purchase_cost' => $row->purchase_cost_minor === null ? null : CatalogueValues::money((int) $row->purchase_cost_minor), 'reason' => $row->reason, 'effective_at' => CarbonImmutable::parse((string) $row->effective_at, 'UTC')->toISOString(), 'actor_name' => $row->actor_name]),
        ]);
    }

    public function store(ProductRequest $request, CatalogueService $service): RedirectResponse
    {
        Gate::authorize('create', Product::class);
        $service->save($request->user(), $request->validated());

        return to_route('products.index')->with('status', 'Product created with zero stock. Opening balances and stock receipts will supply quantities.');
    }

    public function update(ProductRequest $request, Product $product, CatalogueService $service): RedirectResponse
    {
        Gate::authorize('update', $product);
        $service->save($request->user(), $request->validated(), $product);

        return to_route('products.index')->with('status', 'Product updated. Earlier price records are preserved.');
    }

    public function category(Request $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $key = CatalogueValues::nameKey($data['name']);
        try {
            DB::transaction(function () use ($request, $data, $key): void {
                $category = Category::create(['name' => $data['name'], 'name_key' => $key]);
                AccessAudit::record('category.created', $request->user(), null, 'Owner created category.', null, $category->only(['id', 'name']), 'category', $category->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This category already exists.']);
        }

        return back()->with('status', 'Category created.');
    }

    /** @return array<string, mixed> */
    private function present(Product $product, bool $owner): array
    {
        $data = $product->only(['id', 'sku', 'name', 'category_id', 'unit_label', 'quantity_precision', 'reorder_threshold', 'quantity_on_hand', 'barcode', 'is_active', 'version']);
        $data['category_name'] = $product->category->getAttribute('name');
        $data['selling_price'] = CatalogueValues::money($product->selling_price_minor);
        if ($owner) {
            $data['purchase_cost'] = $product->purchase_cost_minor === null ? null : CatalogueValues::money($product->purchase_cost_minor);
            $data['average_cost'] = $product->average_cost_minor === null ? null : CatalogueValues::money($product->average_cost_minor);
            $data['inventory_value'] = $product->inventory_value_minor === null ? null : CatalogueValues::money($product->inventory_value_minor);
        }

        return $data;
    }
}
