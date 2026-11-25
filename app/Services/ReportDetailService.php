<?php

namespace App\Services;

use App\Models\ShopSettings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReportDetailService
{
    /** @return array<string, mixed> */
    public function build(User $actor, string $from, string $to): array
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);

        return PostingTransaction::run(function () use ($from, $to): array {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);

            return $this->snapshot($from, $to);
        }, 'report');
    }

    /** @return array<string, mixed> */
    private function snapshot(string $from, string $to): array
    {
        $sales = DB::table('sales')->where('status', 'posted')->whereBetween('business_date', [$from, $to])->get();
        $payments = DB::table('payments')->whereBetween('business_date', [$from, $to])->get();
        $outflows = DB::table('cash_outflows')->whereBetween('effective_on', [$from, $to])->get();
        $operations = DB::table('shop_operations')->whereBetween('business_date', [$from, $to])->get();
        $days = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $returns = $this->sum($operations->where('business_date', $date)->where('kind', 'return')->pluck('amount_minor')->all());
            $days[] = ['day' => $date, 'net_sales' => $this->sum($sales->where('business_date', $date)->pluck('total_minor')->all())->minus($returns)->__toString(), 'collections' => (string) $this->sum($payments->where('business_date', $date)->where('status', 'verified')->pluck('amount_minor')->all()), 'pending' => (string) $this->sum($payments->where('business_date', $date)->where('status', 'pending')->pluck('amount_minor')->all()), 'money_out' => (string) $this->sum($outflows->where('effective_on', $date)->pluck('amount_minor')->all())->plus($this->sum($operations->where('business_date', $date)->pluck('cash_amount_minor')->all()))];
        }
        $products = DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')->select('products.*', 'categories.name as category')->orderBy('products.name')->get();
        $lines = DB::table('sale_items')->whereIn('sale_id', $sales->pluck('id'))->get();
        $returns = DB::table('shop_operation_items')->join('shop_operations', 'shop_operations.id', '=', 'shop_operation_items.shop_operation_id')->where('shop_operations.kind', 'return')->whereBetween('shop_operations.business_date', [$from, $to])->select('shop_operation_items.*')->get();
        $ranking = $products->map(function (object $product) use ($lines, $returns): array {
            $sold = $lines->where('product_id', $product->id);
            $returned = $returns->where('product_id', $product->id);
            $quantity = BigDecimal::zero();
            foreach ($sold as $line) {
                $quantity = $quantity->plus((string) $line->quantity);
            }
            foreach ($returned as $line) {
                $quantity = $quantity->minus((string) $line->quantity);
            }
            $net = $this->sum($sold->pluck('total_minor')->all())->minus($this->sum($returned->pluck('amount_minor')->all()));
            $cost = $this->sum($sold->pluck('cost_total_minor')->all())->minus($this->sum($returned->where('disposition', 'restock')->pluck('cost_minor')->all()));

            return ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'category' => $product->category, 'unit' => $product->unit_label, 'quantity' => (string) $quantity, 'net_minor' => (string) $net, 'margin_minor' => $sold->whereNull('cost_total_minor')->isNotEmpty() || $returned->whereNull('cost_minor')->isNotEmpty() ? null : (string) $net->minus($cost), 'stock' => (string) $product->quantity_on_hand, 'reorder' => (string) $product->reorder_threshold, 'value_minor' => $product->inventory_value_minor === null ? null : (string) $product->inventory_value_minor, 'low' => BigDecimal::of((string) $product->quantity_on_hand)->isLessThanOrEqualTo((string) $product->reorder_threshold), 'active' => (bool) $product->is_active];
        })->sort(fn (array $a, array $b): int => BigInteger::of($b['net_minor'])->compareTo($a['net_minor']))->values();
        $debts = DB::table('receivables')->leftJoin('customers', 'customers.id', '=', 'receivables.customer_id')->where('remaining_amount_minor', '>', 0)->orderBy('due_on')->orderBy('receivables.id')->get(['receivables.id', 'customer_id', 'customers.name', 'customers.contact', 'origin', 'sale_id', 'originated_on', 'due_on', 'remaining_amount_minor']);
        $today = now('Africa/Nairobi')->toDateString();
        $aging = ['not_due' => BigInteger::zero(), '1_30' => BigInteger::zero(), '31_60' => BigInteger::zero(), '61_90' => BigInteger::zero(), 'over_90' => BigInteger::zero()];
        foreach ($debts as $debt) {
            $daysLate = (int) CarbonImmutable::parse($debt->due_on)->diffInDays(CarbonImmutable::parse($today), false);
            $bucket = $daysLate <= 0 ? 'not_due' : ($daysLate <= 30 ? '1_30' : ($daysLate <= 60 ? '31_60' : ($daysLate <= 90 ? '61_90' : 'over_90')));
            $aging[$bucket] = $aging[$bucket]->plus((string) $debt->remaining_amount_minor);
        }
        $expenses = $outflows->where('kind', 'expense')->groupBy('category')->map(fn ($rows, $category): array => ['category' => $category, 'amount_minor' => (string) $this->sum($rows->pluck('amount_minor')->all())])->values();
        $handovers = DB::table('cash_handovers')->join('users', 'users.id', '=', 'cash_handovers.employee_id')->whereBetween('business_date', [$from, $to])->select('cash_handovers.*', 'users.name')->orderByDesc('business_date')->get()->map(function (object $row): array {
            $current = app(CashReconciliationService::class)->expected((int) $row->employee_id, $row->business_date);

            return [...(array) $row, 'current_expected_minor' => (string) $current, 'stale' => $current !== (int) $row->expected_minor, 'difference_minor' => $row->received_minor === null ? null : (string) ((int) $row->received_minor - $current)];
        });

        return ['damaged' => DB::table('shop_operation_items')->join('shop_operations', 'shop_operations.id', '=', 'shop_operation_items.shop_operation_id')->join('products', 'products.id', '=', 'shop_operation_items.product_id')->where('shop_operation_items.disposition', 'damaged')->select('shop_operations.id', 'shop_operations.sale_id', 'shop_operations.business_date', 'products.sku', 'products.name', 'products.unit_label', 'shop_operation_items.quantity', 'shop_operation_items.cost_minor', 'shop_operations.reason')->orderByDesc('shop_operations.id')->get(), 'daily' => $days, 'products' => $ranking, 'debts' => $debts, 'aging' => array_map(fn (BigInteger $amount): string => (string) $amount, $aging), 'expenses' => $expenses, 'handovers' => $handovers, 'alerts' => ['pending_payments' => DB::table('payments')->where('status', 'pending')->count(), 'pending_counts' => DB::table('stock_counts')->where('status', 'pending')->count(), 'pending_approvals' => DB::table('sale_requests')->where('status', 'pending')->count(), 'low_stock' => $ranking->where('active', true)->where('low', true)->count()], 'accounts' => User::query()->orderBy('name')->get(['id', 'name'])];
    }

    /** @param array<array-key, int|string|null> $amounts */
    private function sum(array $amounts): BigInteger
    {
        $sum = BigInteger::zero();
        foreach ($amounts as $amount) {
            $sum = $sum->plus((string) ($amount ?? 0));
        }

        return $sum;
    }
}
