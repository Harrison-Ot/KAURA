<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class MpesaCodes
{
    public static function exists(?string $code): bool
    {
        return $code !== null && (DB::table('payments')->where('mpesa_code', $code)->exists() || DB::table('cash_outflows')->where('payment_reference', $code)->exists() || DB::table('shop_operations')->where('payment_reference', $code)->exists());
    }
}
