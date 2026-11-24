<?php

namespace App\Services;

use App\Models\ShopSettings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /** @return array<string, mixed> */
    public function build(User $actor, string $from, string $to): array
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);

        return PostingTransaction::run(function () use ($from, $to): array {
            // The shared posting lock keeps the report's ledgers in one consistent snapshot.
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $sales = DB::table('sales')->where('status', 'posted')->whereBetween('business_date', [$from, $to])->get(['sold_by', 'gross_minor', 'discount_minor', 'total_minor', 'cost_total_minor']);
            $payments = DB::table('payments')->whereBetween('business_date', [$from, $to])->get(['handled_by', 'method', 'status', 'purpose', 'amount_minor']);
            $outflows = DB::table('cash_outflows')->whereBetween('effective_on', [$from, $to])->get(['actor_id', 'kind', 'category', 'payment_method', 'amount_minor']);
            $confirmed = $payments->where('status', 'verified');
            $cashIn = $this->sum($confirmed->where('method', 'cash'), 'amount_minor');
            $cashOut = $this->sum($outflows->where('payment_method', 'cash'), 'amount_minor');
            $netSales = $this->sum($sales, 'total_minor');
            $knownCost = $this->sum($sales->whereNotNull('cost_total_minor'), 'cost_total_minor');
            $unknownCostSales = $sales->whereNull('cost_total_minor')->count();
            $expenses = $this->sum($outflows->where('kind', 'expense'), 'amount_minor');
            $summary = [
                'sale_count' => $sales->count(), 'gross_sales' => $this->money($this->sum($sales, 'gross_minor')), 'discounts' => $this->money($this->sum($sales, 'discount_minor')), 'net_sales' => $this->money($netSales),
                'known_sale_cost' => $this->money($knownCost), 'unknown_cost_sales' => $unknownCostSales,
                'gross_margin_estimate' => $unknownCostSales === 0 ? $this->money($netSales->minus($knownCost)) : null,
                'operating_profit_estimate' => $unknownCostSales === 0 ? $this->money($netSales->minus($knownCost)->minus($expenses)) : null,
                'confirmed_collections' => $this->money($this->sum($confirmed, 'amount_minor')), 'cash_confirmed' => $this->money($cashIn),
                'mpesa_recorded' => $this->money($this->sum($payments->where('method', 'manual_mpesa'), 'amount_minor')),
                'mpesa_verified' => $this->money($this->sum($confirmed->where('method', 'manual_mpesa'), 'amount_minor')),
                'mpesa_pending' => $this->money($this->sum($payments->where('method', 'manual_mpesa')->where('status', 'pending'), 'amount_minor')),
                'mpesa_disputed' => $this->money($this->sum($payments->where('method', 'manual_mpesa')->where('status', 'disputed'), 'amount_minor')),
                'confirmed_instalments' => $this->money($this->sum($confirmed->where('purpose', 'instalment'), 'amount_minor')),
                'operating_expenses' => $this->money($expenses), 'stock_purchases' => $this->money($this->sum($outflows->where('kind', 'stock_purchase'), 'amount_minor')),
                'owner_withdrawals' => $this->money($this->sum($outflows->where('kind', 'owner_withdrawal'), 'amount_minor')),
                'refunds' => $this->money($this->sum($outflows->where('kind', 'refund'), 'amount_minor')),
                'net_collections_after_refunds' => $this->money($this->sum($confirmed, 'amount_minor')->minus($this->sum($outflows->where('kind', 'refund'), 'amount_minor'))),
                'cash_out' => $this->money($cashOut), 'mpesa_out' => $this->money($this->sum($outflows->where('payment_method', 'mpesa'), 'amount_minor')),
                'net_cash_movement' => $this->money($cashIn->minus($cashOut)),
            ];
            $employeeIds = $sales->pluck('sold_by')->merge($payments->pluck('handled_by'))->merge($outflows->pluck('actor_id'))->unique();
            $employees = User::query()->whereIn('id', $employeeIds)->orderBy('name')->get()->map(function (User $user) use ($sales, $payments, $outflows): array {
                $ownSales = $sales->where('sold_by', $user->id);
                $ownPayments = $payments->where('handled_by', $user->id);
                $ownOutflows = $outflows->where('actor_id', $user->id);
                $cash = $this->sum($ownPayments->where('status', 'verified')->where('method', 'cash'), 'amount_minor');
                $out = $this->sum($ownOutflows->where('payment_method', 'cash'), 'amount_minor');

                return ['id' => $user->id, 'name' => $user->name, 'active' => $user->is_active, 'sale_count' => $ownSales->count(), 'net_sales' => $this->money($this->sum($ownSales, 'total_minor')),
                    'cash_confirmed' => $this->money($cash), 'mpesa_verified' => $this->money($this->sum($ownPayments->where('status', 'verified')->where('method', 'manual_mpesa'), 'amount_minor')),
                    'mpesa_pending' => $this->money($this->sum($ownPayments->where('status', 'pending')->where('method', 'manual_mpesa'), 'amount_minor')),
                    'mpesa_disputed' => $this->money($this->sum($ownPayments->where('status', 'disputed')->where('method', 'manual_mpesa'), 'amount_minor')),
                    'cash_out' => $this->money($out), 'net_cash_movement' => $this->money($cash->minus($out))];
            })->all();
            $debts = DB::table('receivables')->where('remaining_amount_minor', '>', 0)->get(['customer_id', 'origin', 'remaining_amount_minor', 'due_on']);
            $products = DB::table('products')->get(['inventory_value_minor']);
            $voids = DB::table('sale_voids')->where('funds_status', 'needs_review')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('void_resolutions')->whereColumn('void_resolutions.sale_void_id', 'sale_voids.id'))->get(['confirmed_minor']);
            $credits = DB::table('customer_credits')->get(['remaining_amount_minor']);
            $voidClaims = DB::table('payments')->join('sale_voids', 'sale_voids.sale_id', '=', 'payments.sale_id')->where('payments.status', 'pending')->get(['payments.amount_minor']);
            $current = [
                'retained_customer_credit' => $this->money($this->sum($credits, 'remaining_amount_minor')),
                'void_confirmed_funds_held' => $this->money($this->sum($voids, 'confirmed_minor')),
                'void_pending_claims' => $this->money($this->sum($voidClaims, 'amount_minor')),
                'outstanding' => $this->money($this->sum($debts, 'remaining_amount_minor')),
                'opening_debt' => $this->money($this->sum($debts->where('origin', 'opening_balance'), 'remaining_amount_minor')),
                'credit_sale_debt' => $this->money($this->sum($debts->where('origin', 'credit_sale'), 'remaining_amount_minor')),
                'verification_exposure' => $this->money($this->sum($debts->where('origin', 'payment_verification'), 'remaining_amount_minor')),
                'unassigned_debt' => $this->money($this->sum($debts->whereNull('customer_id'), 'remaining_amount_minor')),
                'overdue_credit_and_opening' => $this->money($this->sum($debts->whereIn('origin', ['opening_balance', 'credit_sale'])->filter(fn (object $row): bool => $row->due_on < now('Africa/Nairobi')->toDateString()), 'remaining_amount_minor')),
                'known_inventory_value' => $this->money($this->sum($products->whereNotNull('inventory_value_minor'), 'inventory_value_minor')), 'unknown_cost_listings' => $products->whereNull('inventory_value_minor')->count(),
            ];

            return ['from' => $from, 'to' => $to, 'generated_at' => now()->toISOString(), 'opening_ready' => $settings->opening_completed_at !== null, 'summary' => $summary, 'employees' => $employees, 'current' => $current];
        }, 'report');
    }

    /** @param Collection<int, \stdClass> $rows */
    private function sum(Collection $rows, string $field): BigInteger
    {
        $total = BigInteger::zero();
        foreach ($rows as $row) {
            $total = $total->plus((string) $row->{$field});
        }

        return $total;
    }

    private function money(BigInteger $minor): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, 2);
    }
}
