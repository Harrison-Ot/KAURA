<?php

namespace App\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostingTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(Closure $work, string $errorField): mixed
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction($work, 5);
            } catch (QueryException $exception) {
                $busy = DB::getDriverName() === 'sqlite' && (str_contains(strtolower($exception->getMessage()), 'database is locked') || str_contains(strtolower($exception->getMessage()), 'database table is locked'));
                if (! $busy) {
                    throw $exception;
                }
                if ($attempt === 4) {
                    throw ValidationException::withMessages([$errorField => 'Posting is busy. Retry the same action; existing posted results will not be duplicated.']);
                }
                usleep(20_000 * ($attempt + 1));
            }
        }
        throw new \LogicException('Posting retries exhausted.');
    }
}
