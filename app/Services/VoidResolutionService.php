<?php

namespace App\Services;

use App\Models\CashOutflow;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Models\User;
use App\Models\VoidResolution;
use App\Support\MpesaCodes;
use App\Support\StockArithmetic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidResolutionService
{
    /** @param array<string, mixed> $data */
    public function resolve(User $actor, SaleVoid $void, array $data): VoidResolution
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);
        unset($data['confirm']);

        return PostingTransaction::run(function () use ($actor, $void, $data): VoidResolution {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $void = SaleVoid::query()->lockForUpdate()->findOrFail($void->id);
            $hash = hash('sha256', json_encode([$void->id, $data], JSON_THROW_ON_ERROR));
            $existing = VoidResolution::query()->where('sale_void_id', $void->id)->orWhere('submission_key', $data['submission_key'])->first();
            if ($existing !== null) {
                if ($existing->sale_void_id !== $void->id || $existing->payload_hash !== $hash || $existing->actor_id !== $actor->id) {
                    throw ValidationException::withMessages(['resolution' => 'These funds or this key were already resolved with different terms. Open the existing record.']);
                }

                return $existing;
            }
            if ($void->stock_status === 'pending' || $void->confirmed_minor === 0 || Payment::query()->where('sale_id', $void->sale_id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['resolution' => 'Finish the physical goods review and decide pending checkout claims first. Only positive confirmed funds can be resolved.']);
            }
            $sale = Sale::query()->lockForUpdate()->findOrFail($void->sale_id);
            $credit = $data['kind'] === 'customer_credit';
            $customer = $credit ? Customer::query()->where('id', $data['customer_id'])->lockForUpdate()->firstOrFail() : null;
            if ($customer !== null && $sale->getAttribute('customer_id') !== null && (int) $sale->getAttribute('customer_id') !== $customer->id) {
                throw ValidationException::withMessages(['customer_id' => 'Retained credit must belong to this sale\'s named customer.']);
            }
            if (! $credit && $data['payment_method'] === 'mpesa' && MpesaCodes::exists($data['payment_reference'])) {
                throw ValidationException::withMessages(['payment_reference' => 'This outgoing or incoming M-Pesa code was already recorded.']);
            }
            if (! $credit) {
                StockArithmetic::total((int) CashOutflow::query()->sum('amount_minor'), $void->confirmed_minor, 'resolution');
            }
            $resolution = VoidResolution::create(['sale_void_id' => $void->id, 'submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'kind' => $data['kind'], 'amount_minor' => $void->confirmed_minor,
                'actor_id' => $actor->id, 'customer_id' => $customer?->id, 'customer_snapshot' => $customer?->only(['name', 'contact']), 'recipient' => $data['recipient'], 'reason' => $data['reason'],
                'payment_method' => $credit ? null : $data['payment_method'], 'payment_reference' => ! $credit && $data['payment_method'] === 'mpesa' ? $data['payment_reference'] : null, 'business_date' => now('Africa/Nairobi')->toDateString(), 'created_at' => now()]);
            if ($customer !== null) {
                StockArithmetic::total((int) DB::table('customer_credits')->sum('remaining_amount_minor'), $void->confirmed_minor, 'resolution');
                DB::table('customer_credits')->insert(['void_resolution_id' => $resolution->id, 'customer_id' => $customer->id, 'original_amount_minor' => $void->confirmed_minor, 'remaining_amount_minor' => $void->confirmed_minor, 'created_at' => now()]);
                $customer->version++;
                $customer->save();
            } else {
                CashOutflow::create(['void_resolution_id' => $resolution->id, 'kind' => 'refund', 'amount_minor' => $void->confirmed_minor, 'payment_method' => $data['payment_method'], 'payment_reference' => $resolution->getAttribute('payment_reference'), 'recipient' => $data['recipient'], 'reason' => $data['reason'], 'actor_id' => $actor->id, 'effective_on' => $resolution->getAttribute('business_date'), 'created_at' => now()]);
            }
            AccessAudit::record('sale_void.funds_resolved', $actor, null, $data['reason'], ['unresolved_minor' => $void->confirmed_minor], ['resolution_id' => $resolution->id, 'kind' => $data['kind'], 'amount_minor' => $void->confirmed_minor, 'customer_id' => $customer?->id], 'sale_void', $void->id);

            return $resolution;
        }, 'resolution');
    }
}
