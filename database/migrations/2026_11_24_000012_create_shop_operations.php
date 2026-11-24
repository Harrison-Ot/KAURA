<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('kind', 40)->index();
            $table->string('source_key')->nullable()->unique();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('receivable_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_credit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_outflow_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->bigInteger('cash_amount_minor')->default(0);
            $table->bigInteger('debt_amount_minor')->default(0);
            $table->bigInteger('cost_minor')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 100)->nullable()->unique();
            $table->string('recipient')->nullable();
            $table->text('reason');
            $table->date('business_date')->index();
            $table->timestamp('created_at');
        });
        Schema::create('shop_operation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('cost_minor')->nullable();
            $table->string('disposition', 20);
            $table->unique(['shop_operation_id', 'product_id']);
        });
        $this->dropSource();
        Schema::table('stock_movements', fn (Blueprint $table) => $table->foreignId('shop_operation_item_id')->nullable()->unique()->constrained()->restrictOnDelete());
        $this->source(true);
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->date('business_date');
            $table->bigInteger('expected_minor');
            $table->unsignedBigInteger('declared_minor');
            $table->text('employee_note');
            $table->bigInteger('received_minor')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('owner_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'business_date']);
        });
        Schema::create('catalogue_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_key')->unique();
            $table->string('payload_hash', 64);
            $table->json('rows');
            $table->text('source_note');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('tax_registration')->nullable();
            $table->string('tax_label', 50)->nullable();
            $table->decimal('future_tax_rate', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('shop_operations')->exists() || DB::table('cash_handovers')->exists() || DB::table('catalogue_imports')->exists()) {
            throw new RuntimeException('Shop operation history exists. Restore matching code/database backups.');
        }
        $this->dropSource();
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropUnique(['shop_operation_item_id']));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('shop_operation_item_id'));
        Schema::dropIfExists('shop_operation_items');
        Schema::dropIfExists('shop_operations');
        Schema::dropIfExists('cash_handovers');
        Schema::dropIfExists('catalogue_imports');
        Schema::table('shop_settings', fn (Blueprint $table) => $table->dropColumn(['tax_registration', 'tax_label', 'future_tax_rate']));
        $this->source(false);
    }

    private function dropSource(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS stock_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS stock_source_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_source_check');
        }
    }

    private function source(bool $operations): void
    {
        $columns = ['batch_item_id', 'sale_item_id', 'stock_count_item_id', 'sale_void_item_id', ...($operations ? ['shop_operation_item_id'] : [])];
        if (DB::getDriverName() === 'sqlite') {
            $sum = implode(' + ', array_map(fn (string $column): string => '(NEW.'.$column.' IS NOT NULL)', $columns));
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER stock_source_'.strtolower($operation).' BEFORE '.$operation.' ON stock_movements WHEN ('.$sum.') <> 1 BEGIN SELECT RAISE(ABORT, \'Stock movement requires exactly one source\'); END');
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            $sum = implode(' + ', array_map(fn (string $column): string => '(CASE WHEN '.$column.' IS NOT NULL THEN 1 ELSE 0 END)', $columns));
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_source_check CHECK (('.$sum.') = 1)');
        }
    }
};
