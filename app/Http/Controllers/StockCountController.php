<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockCountRequest;
use App\Models\Product;
use App\Models\ShopSettings;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Services\StockCountService;
use App\Support\CatalogueValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockCountController extends Controller
{
    public function index(Request $request): Response
    {
        $query = StockCount::query()->orderByDesc('id');
        if ($request->user()->role !== 'owner') {
            $query->where('counted_by', $request->user()->id);
        }

        return Inertia::render('counts/index', ['counts' => $query->paginate(20)->through(fn (StockCount $count): array => [...$count->only(['id', 'reference', 'status', 'counted_at', 'reason']), 'counted_by_name' => User::query()->where('id', $count->counted_by)->value('name')])]);
    }

    public function create(): Response
    {
        return Inertia::render('counts/create', ['products' => Product::query()->orderBy('name')->get(['id', 'sku', 'name', 'unit_label', 'quantity_precision', 'quantity_on_hand', 'version', 'is_active']), 'submission_key' => (string) Str::uuid(), 'openingReady' => ShopSettings::query()->findOrFail(1)->opening_completed_at !== null]);
    }

    public function store(StockCountRequest $request, StockCountService $service): RedirectResponse
    {
        return to_route('counts.show', $service->submit($request->user(), $request->validated()));
    }

    public function show(Request $request, StockCount $count): Response
    {
        Gate::authorize('view', $count);
        $owner = $request->user()->role === 'owner';

        return Inertia::render('counts/show', ['count' => [...$count->only(['id', 'reference', 'status', 'reason', 'counted_at', 'decided_at', 'decision_reason']), 'counted_by_name' => User::query()->where('id', $count->counted_by)->value('name'), 'decided_by_name' => User::query()->where('id', $count->getAttribute('decided_by'))->value('name')],
            'items' => $count->items->map(function (StockCountItem $item) use ($owner): array {
                $product = Product::query()->findOrFail($item->product_id);

                return [...$item->only(['id', 'name', 'sku', 'unit_label', 'system_quantity', 'counted_quantity', 'quantity_delta']), 'stale' => $product->version !== $item->record_version, ...($owner ? ['value_before' => $item->value_before_minor === null ? null : CatalogueValues::money($item->value_before_minor), 'value_after' => $item->value_after_minor === null ? null : CatalogueValues::money($item->value_after_minor)] : [])];
            })]);
    }

    public function decide(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        $service->decide($request->user(), $count, $data['decision'], $data['reason']);

        return to_route('counts.show', $count);
    }
}
