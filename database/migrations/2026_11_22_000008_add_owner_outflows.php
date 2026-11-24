<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_outflows', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable()->change();
            $table->uuid('submission_key')->nullable()->unique();
            $table->string('payload_hash', 64)->nullable();
            $table->string('category', 30)->nullable();
            $table->string('recipient')->nullable();
            $table->text('reason')->nullable();
        });
        $expression = "((kind = 'stock_purchase' AND batch_id IS NOT NULL AND submission_key IS NULL) OR (kind IN ('expense', 'owner_withdrawal') AND batch_id IS NULL AND submission_key IS NOT NULL AND payload_hash IS NOT NULL AND recipient IS NOT NULL AND reason IS NOT NULL AND ((kind = 'expense' AND category IS NOT NULL) OR (kind = 'owner_withdrawal' AND category IS NULL))))";
        if (DB::getDriverName() === 'sqlite') {
            $expression = preg_replace('/\b(kind|batch_id|submission_key|payload_hash|recipient|reason|category)\b/', 'NEW.$1', $expression);
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER outflow_source_'.strtolower($operation).' BEFORE '.$operation.' ON cash_outflows WHEN NOT '.$expression.' BEGIN SELECT RAISE(ABORT, \'Outflow requires a purchase or owner entry source\'); END');
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_outflows ADD CONSTRAINT outflow_source_check CHECK '.$expression);
        }
    }

    public function down(): void
    {
        if (DB::table('cash_outflows')->whereNull('batch_id')->exists()) {
            throw new RuntimeException('Owner outflow history exists. Restore matching code/database backups.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS outflow_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS outflow_source_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_outflows DROP CONSTRAINT outflow_source_check');
        }
        Schema::table('cash_outflows', fn (Blueprint $table) => $table->dropUnique(['submission_key']));
        Schema::table('cash_outflows', function (Blueprint $table) {
            $table->dropColumn(['submission_key', 'payload_hash', 'category', 'recipient', 'reason']);
            $table->unsignedBigInteger('batch_id')->nullable(false)->change();
        });
    }
};
