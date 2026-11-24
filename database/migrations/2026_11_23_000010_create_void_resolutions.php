<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('void_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_void_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('payload_hash', 64);
            $table->enum('kind', ['refund', 'customer_credit']);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('customer_snapshot')->nullable();
            $table->string('recipient');
            $table->text('reason');
            $table->enum('payment_method', ['cash', 'mpesa'])->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->date('business_date');
            $table->timestamp('created_at');
        });
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('void_resolution_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('original_amount_minor');
            $table->unsignedBigInteger('remaining_amount_minor');
            $table->timestamp('created_at');
        });
        $this->dropSource();
        Schema::table('cash_outflows', fn (Blueprint $table) => $table->foreignId('void_resolution_id')->nullable()->unique()->constrained()->restrictOnDelete());
        $this->source(true);
    }

    public function down(): void
    {
        if (DB::table('void_resolutions')->exists()) {
            throw new RuntimeException('Refund or customer credit history exists. Restore matching code/database backups.');
        }
        $this->dropSource();
        Schema::table('cash_outflows', fn (Blueprint $table) => $table->dropUnique(['void_resolution_id']));
        Schema::table('cash_outflows', fn (Blueprint $table) => $table->dropConstrainedForeignId('void_resolution_id'));
        Schema::dropIfExists('customer_credits');
        Schema::dropIfExists('void_resolutions');
        $this->source(false);
    }

    private function dropSource(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS outflow_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS outflow_source_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_outflows DROP CONSTRAINT outflow_source_check');
        }
    }

    private function source(bool $refunds): void
    {
        $notRefund = $refunds ? ' AND void_resolution_id IS NULL' : '';
        $refund = $refunds ? " OR (kind = 'refund' AND batch_id IS NULL AND void_resolution_id IS NOT NULL AND submission_key IS NULL AND category IS NULL AND recipient IS NOT NULL AND reason IS NOT NULL)" : '';
        $expression = "((kind = 'stock_purchase' AND batch_id IS NOT NULL AND submission_key IS NULL".$notRefund.") OR (kind IN ('expense', 'owner_withdrawal') AND batch_id IS NULL AND submission_key IS NOT NULL AND payload_hash IS NOT NULL AND recipient IS NOT NULL AND reason IS NOT NULL".$notRefund." AND ((kind = 'expense' AND category IS NOT NULL) OR (kind = 'owner_withdrawal' AND category IS NULL)))".$refund.')';
        if (DB::getDriverName() === 'sqlite') {
            $expression = preg_replace('/\b(kind|batch_id|submission_key|payload_hash|recipient|reason|category|void_resolution_id)\b/', 'NEW.$1', $expression);
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER outflow_source_'.strtolower($operation).' BEFORE '.$operation.' ON cash_outflows WHEN NOT '.$expression.' BEGIN SELECT RAISE(ABORT, \'Outflow requires a purchase, owner entry or refund source\'); END');
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_outflows ADD CONSTRAINT outflow_source_check CHECK '.$expression);
        }
    }
};
