<?php

namespace App\Services;

use App\Models\CashOutflow;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\MpesaCodes;
use App\Support\StockArithmetic;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OwnerOutflowService
{
    /** @param array<string, mixed> $data */
    public function record(User $actor, array $data): CashOutflow
    {
        Gate::forUser($actor)->authorize('create', CashOutflow::class);
        unset($data['confirm']);

        return PostingTransaction::run(function () use ($actor, $data): CashOutflow {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = CashOutflow::query()->where('submission_key', $data['submission_key'])->first();
            if ($existing !== null) {
                abort_unless($existing->actor_id === $actor->id, 403);
                if ($existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['submission_key' => 'This key belongs to a different entry. Start a new money-out entry.']);
                }

                return $existing;
            }
            if ($settings->opening_completed_at === null) {
                throw ValidationException::withMessages(['amount' => 'Sign off opening balances before recording new expenses or withdrawals.']);
            }
            $amount = StockArithmetic::totalAmount((string) $data['amount'], 'amount');
            if ($amount === 0) {
                throw ValidationException::withMessages(['amount' => 'Record a positive amount that has actually left the shop.']);
            }
            StockArithmetic::total((int) CashOutflow::query()->sum('amount_minor'), $amount, 'amount');
            if ($data['payment_method'] === 'mpesa' && MpesaCodes::exists($data['payment_reference'])) {
                throw ValidationException::withMessages(['payment_reference' => 'This M-Pesa code was already recorded. Check the existing entry.']);
            }
            $outflow = CashOutflow::create(['submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'kind' => $data['kind'], 'category' => $data['kind'] === 'expense' ? $data['category'] : null,
                'recipient' => $data['recipient'], 'reason' => $data['reason'], 'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_method'] === 'mpesa' ? $data['payment_reference'] : null,
                'amount_minor' => $amount, 'actor_id' => $actor->id, 'effective_on' => now('Africa/Nairobi')->toDateString(), 'created_at' => now()]);
            AccessAudit::record('outflow.recorded', $actor, null, $data['reason'], null, $outflow->only(['kind', 'category', 'recipient', 'amount_minor', 'payment_method', 'payment_reference', 'effective_on']), 'cash_outflow', $outflow->id);

            return $outflow;
        }, 'amount');
    }
}
