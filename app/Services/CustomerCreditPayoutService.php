<?php

namespace App\Services;

use App\Models\CashOutflow;
use App\Models\Customer;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\MpesaCodes;
use App\Support\StockArithmetic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerCreditPayoutService
{
    /** @param array<string, mixed> $data */
    public function pay(User $actor, int $creditId, array $data): int
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);
        unset($data['confirm']);

        return PostingTransaction::run(function () use ($actor, $creditId, $data): int {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $hash = hash('sha256', json_encode([$creditId, $data], JSON_THROW_ON_ERROR));
            $existing = DB::table('customer_credit_payouts')->where('customer_credit_id', $creditId)->orWhere('submission_key', $data['submission_key'])->first();
            if ($existing !== null) {
                if ((int) $existing->customer_credit_id !== $creditId || $existing->payload_hash !== $hash || (int) $existing->actor_id !== $actor->id) {
                    throw ValidationException::withMessages(['payout' => 'This credit or submission key was already paid with different terms.']);
                }

                return (int) $existing->cash_outflow_id;
            }
            $credit = DB::table('customer_credits')->where('id', $creditId)->lockForUpdate()->first();
            abort_if($credit === null, 404);
            $amount = (int) $credit->remaining_amount_minor;
            if ($amount === 0) {
                throw ValidationException::withMessages(['payout' => 'This credit has no remaining balance.']);
            }
            if ($data['payment_method'] === 'mpesa' && MpesaCodes::exists($data['payment_reference'])) {
                throw ValidationException::withMessages(['payment_reference' => 'This outgoing or incoming M-Pesa code was already recorded.']);
            }
            StockArithmetic::total((int) CashOutflow::query()->sum('amount_minor'), $amount, 'payout');
            $outflow = CashOutflow::create(['void_resolution_id' => $credit->void_resolution_id, 'kind' => 'refund', 'amount_minor' => $amount, 'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_method'] === 'mpesa' ? $data['payment_reference'] : null, 'recipient' => $data['recipient'], 'reason' => $data['reason'], 'actor_id' => $actor->id, 'effective_on' => now('Africa/Nairobi')->toDateString(), 'created_at' => now()]);
            DB::table('customer_credit_payouts')->insert(['customer_credit_id' => $creditId, 'cash_outflow_id' => $outflow->id, 'submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'amount_minor' => $amount, 'actor_id' => $actor->id, 'recipient' => $data['recipient'], 'reason' => $data['reason'], 'created_at' => now()]);
            DB::table('customer_credits')->where('id', $creditId)->update(['remaining_amount_minor' => 0]);
            $customer = Customer::query()->lockForUpdate()->findOrFail((int) $credit->customer_id);
            $customer->version++;
            $customer->save();
            AccessAudit::record('customer_credit.paid_out', $actor, null, $data['reason'], ['remaining_minor' => $amount], ['remaining_minor' => 0, 'outflow_id' => $outflow->id], 'customer_credit', $creditId);

            return $outflow->id;
        }, 'payout');
    }
}
