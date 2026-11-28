<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->uuid('submission_key')->unique();
            $table->string('payload_hash', 64);
            $table->enum('status', ['posted', 'voided'])->default('posted');
            $table->foreignId('sold_by')->constrained('users')->restrictOnDelete();
            $table->string('employee_name');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('customer_snapshot')->nullable();
            $table->json('shop_snapshot');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_total_minor')->nullable();
            $table->unsignedBigInteger('cash_tendered_minor');
            $table->unsignedBigInteger('change_minor');
            $table->date('business_date')->index();
            $table->timestamp('sold_at');
            $table->timestamps();
            $table->index(['sold_by', 'business_date', 'id']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('sku', 50);
            $table->string('name');
            $table->string('unit_label', 30);
            $table->unsignedTinyInteger('quantity_precision');
            $table->decimal('quantity', 12, 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('unit_cost_minor')->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_total_minor')->nullable();
            $table->unique(['sale_id', 'position']);
            $table->unique(['sale_id', 'product_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('posting_key', 100)->unique();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('handled_by')->constrained('users')->restrictOnDelete();
            $table->enum('method', ['cash', 'manual_mpesa']);
            $table->enum('status', ['pending', 'verified', 'disputed']);
            $table->unsignedBigInteger('amount_minor');
            $table->string('mpesa_code', 100)->nullable()->unique();
            $table->string('receiving_number', 20)->nullable();
            $table->date('business_date')->index();
            $table->timestamp('received_at');
            $table->index(['handled_by', 'business_date', 'id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_item_id')->nullable()->change();
            $table->foreignId('sale_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER stock_source_'.strtolower($operation).' BEFORE '.$operation.' ON stock_movements WHEN ((NEW.batch_item_id IS NULL) = (NEW.sale_item_id IS NULL)) BEGIN SELECT RAISE(ABORT, \'Stock movement requires exactly one source\'); END');
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_source_check CHECK ((batch_item_id IS NULL) <> (sale_item_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::table('sales')->exists()) {
            throw new RuntimeException('Sales are posted. Restore a matching database backup instead of dropping financial history.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS stock_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS stock_source_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_source_check');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['sale_item_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_item_id');
            $table->unsignedBigInteger('batch_item_id')->nullable(false)->change();
        });
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
