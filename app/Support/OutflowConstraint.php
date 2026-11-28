<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class OutflowConstraint
{
    public static function restoreAfterSqliteRebuild(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        $source = "((NEW.kind = 'stock_purchase' AND NEW.batch_id IS NOT NULL AND NEW.submission_key IS NULL AND NEW.void_resolution_id IS NULL) OR (NEW.kind IN ('expense', 'owner_withdrawal') AND NEW.batch_id IS NULL AND NEW.submission_key IS NOT NULL AND NEW.payload_hash IS NOT NULL AND NEW.recipient IS NOT NULL AND NEW.reason IS NOT NULL AND NEW.void_resolution_id IS NULL AND ((NEW.kind = 'expense' AND NEW.category IS NOT NULL) OR (NEW.kind = 'owner_withdrawal' AND NEW.category IS NULL))) OR (NEW.kind = 'refund' AND NEW.batch_id IS NULL AND NEW.void_resolution_id IS NOT NULL AND NEW.submission_key IS NULL AND NEW.category IS NULL AND NEW.recipient IS NOT NULL AND NEW.reason IS NOT NULL))";
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::statement('DROP TRIGGER IF EXISTS outflow_source_'.strtolower($operation));
            DB::statement('CREATE TRIGGER outflow_source_'.strtolower($operation).' BEFORE '.$operation.' ON cash_outflows WHEN NOT '.$source." BEGIN SELECT RAISE(ABORT, 'Outflow requires a purchase, owner entry or refund source'); END");
        }
    }
}
