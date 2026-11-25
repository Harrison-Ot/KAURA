<?php

namespace App\Services;

use App\Models\ShopSettings;
use App\Models\User;
use App\Support\StockArithmetic;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashReconciliationService
{
    public function expected(int $employeeId, string $day): int
    {
        $incoming = DB::table('payments')->where('handled_by', $employeeId)->where('business_date', $day)->where('method', 'cash')->where('status', 'verified')->pluck('amount_minor');
        $outgoing = DB::table('cash_outflows')->where('actor_id', $employeeId)->where('effective_on', $day)->where('payment_method', 'cash')->pluck('amount_minor');
        $adjustments = DB::table('shop_operations')->where('actor_id', $employeeId)->where('business_date', $day)->where('payment_method', 'cash')->pluck('cash_amount_minor');
        $total = BigInteger::zero();
        foreach ($incoming as $amount) {
            $total = $total->plus((string) $amount);
        }
        foreach ($outgoing->merge($adjustments) as $amount) {
            $total = $total->minus((string) $amount);
        }
        if ($total->abs()->isGreaterThan(StockArithmetic::MAX_TOTAL_MINOR)) {
            throw ValidationException::withMessages(['handover' => 'Day total exceeds the supported handover range. Contact the owner.']);
        }

        return $total->toInt();
    }

    public function submit(User $actor, string $day, string $amount, string $note): void
    {
        abort_unless($actor->is_active && in_array($actor->role, ['owner', 'employee'], true), 403);
        PostingTransaction::run(function () use ($actor, $day, $amount, $note): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $declared = StockArithmetic::totalAmount($amount, 'amount');
            $existing = DB::table('cash_handovers')->where('employee_id', $actor->id)->where('business_date', $day)->first();
            if ($existing !== null) {
                if ((int) $existing->declared_minor === $declared && $existing->employee_note === $note) {
                    return;
                }
                throw ValidationException::withMessages(['handover' => 'This day was already submitted. Ask the owner to review the difference.']);
            }
            $id = DB::table('cash_handovers')->insertGetId(['employee_id' => $actor->id, 'business_date' => $day, 'expected_minor' => $this->expected($actor->id, $day), 'declared_minor' => $declared, 'employee_note' => $note, 'created_at' => now(), 'updated_at' => now()]);
            AccessAudit::record('cash.handover_submitted', $actor, $actor, $note, null, ['declared_minor' => $declared, 'business_date' => $day], 'cash_handover', $id);
        }, 'handover');
    }

    public function review(User $actor, int $id, string $amount, string $note): void
    {
        abort_unless($actor->role === 'owner' && $actor->is_active, 403);
        PostingTransaction::run(function () use ($actor, $id, $amount, $note): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $handover = DB::table('cash_handovers')->where('id', $id)->lockForUpdate()->first();
            abort_if($handover === null, 404);
            $received = StockArithmetic::totalAmount($amount, 'received');
            $expected = $this->expected((int) $handover->employee_id, $handover->business_date);
            if ($handover->reviewed_at !== null && (int) $handover->expected_minor === $expected) {
                if ((int) $handover->received_minor === $received && $handover->owner_note === $note) {
                    return;
                }
                throw ValidationException::withMessages(['handover' => 'This handover already has a final owner review.']);
            }
            DB::table('cash_handovers')->where('id', $id)->update(['expected_minor' => $expected, 'received_minor' => $received, 'reviewed_by' => $actor->id, 'owner_note' => $note, 'reviewed_at' => now(), 'updated_at' => now()]);
            AccessAudit::record('cash.handover_reviewed', $actor, null, $note, (array) $handover, ['expected_minor' => $expected, 'received_minor' => $received, 'difference_minor' => $received - $expected], 'cash_handover', $id);
        }, 'handover');
    }
}
