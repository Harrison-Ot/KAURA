<?php

namespace App\Services;

use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Support\CatalogueValues;

class SaleSettlement
{
    /** @return array{confirmed: string, pending: string, disputed: string, balance_due: string, state: string} */
    public static function summary(Sale $sale): array
    {
        $sale->loadMissing('payments');
        $confirmed = (int) $sale->payments->where('status', 'verified')->sum('amount_minor');
        $pending = (int) $sale->payments->where('status', 'pending')->sum('amount_minor');
        $disputed = (int) $sale->payments->where('status', 'disputed')->sum('amount_minor');
        if ($sale->getAttribute('status') === 'voided') {
            $void = SaleVoid::query()->where('sale_id', $sale->id)->first();

            return ['confirmed' => CatalogueValues::money($void === null ? $confirmed : $void->confirmed_minor), 'pending' => CatalogueValues::money($pending), 'disputed' => CatalogueValues::money($disputed), 'balance_due' => '0.00', 'state' => 'voided'];
        }
        $receivable = Receivable::query()->where('sale_id', $sale->id)->first();
        $balance = $receivable === null ? max(0, $sale->total_minor - $confirmed) : $receivable->remaining_amount_minor;
        $confirmed = $sale->total_minor - $balance;

        return [
            'confirmed' => CatalogueValues::money($confirmed), 'pending' => CatalogueValues::money($pending), 'disputed' => CatalogueValues::money($disputed),
            'balance_due' => CatalogueValues::money($balance),
            'state' => $balance === 0 ? 'paid' : ($disputed > 0 ? 'disputed' : ($pending > 0 ? 'pending_verification' : 'credit_due')),
        ];
    }
}
