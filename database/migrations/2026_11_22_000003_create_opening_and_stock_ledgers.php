<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->string('shop_name')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('contact', 100)->nullable();
            $table->string('mpesa_number', 20)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->date('opening_as_of')->nullable();
            $table->timestamp('opening_completed_at')->nullable();
            $table->foreignId('opening_completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('shop_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_value_minor')->nullable()->default(0);
            $table->unsignedBigInteger('average_cost_minor')->nullable();
        });
        DB::table('products')->where('quantity_on_hand', '>', 0)->update(['inventory_value_minor' => null]);

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->timestamps();
        });

        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->uuid('submission_key')->unique();
            $table->string('payload_hash', 64);
            $table->enum('kind', ['opening_stock', 'opening_debt', 'stock_receipt']);
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->date('effective_on');
            $table->text('source_note');
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('delivery_reference', 100)->nullable();
            $table->string('delivery_key', 64)->nullable()->unique();
            $table->enum('payment_method', ['cash', 'mpesa'])->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->unsignedBigInteger('amount_paid_minor')->nullable();
            $table->unsignedBigInteger('total_minor')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });

        Schema::create('inventory_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('record_version');
            $table->string('label');
            $table->string('sku', 50)->nullable();
            $table->string('unit_label', 30)->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->unsignedBigInteger('unit_cost_minor')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->date('due_on')->nullable();
            $table->unique(['batch_id', 'position']);
            $table->unique(['batch_id', 'product_id']);
            $table->unique(['batch_id', 'customer_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_item_id')->unique()->constrained('inventory_batch_items')->restrictOnDelete();
            $table->foreignId('opening_product_id')->nullable()->unique()->constrained('products')->restrictOnDelete();
            $table->string('kind', 30);
            $table->decimal('quantity_delta', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->bigInteger('value_delta_minor')->nullable();
            $table->date('effective_on');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['product_id', 'id']);
        });

        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('opening_customer_id')->nullable()->unique()->constrained('customers')->restrictOnDelete();
            $table->foreignId('batch_item_id')->nullable()->unique()->constrained('inventory_batch_items')->restrictOnDelete();
            $table->string('origin', 30);
            $table->unsignedBigInteger('original_amount_minor');
            $table->unsignedBigInteger('remaining_amount_minor');
            $table->date('originated_on');
            $table->date('due_on');
            $table->text('source_note');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['customer_id', 'originated_on', 'id']);
        });

        Schema::create('cash_outflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->unique()->constrained('inventory_batches')->restrictOnDelete();
            $table->string('kind', 30);
            $table->enum('payment_method', ['cash', 'mpesa']);
            $table->string('payment_reference', 100)->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->date('effective_on');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_outflows');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_batch_items');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('suppliers');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['inventory_value_minor', 'average_cost_minor']);
        });
        Schema::dropIfExists('shop_settings');
    }
};
