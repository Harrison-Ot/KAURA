<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->uuid('submission_key')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('counted_at');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('record_version');
            $table->string('name');
            $table->string('sku', 50);
            $table->string('unit_label', 30);
            $table->decimal('system_quantity', 12, 3);
            $table->decimal('counted_quantity', 12, 3);
            $table->decimal('quantity_delta', 12, 3);
            $table->unsignedBigInteger('value_before_minor')->nullable();
            $table->unsignedBigInteger('value_after_minor')->nullable();
            $table->unique(['stock_count_id', 'product_id']);
            $table->unique(['stock_count_id', 'position']);
        });
        $this->dropSourceConstraint();
        Schema::table('stock_movements', fn (Blueprint $table) => $table->foreignId('stock_count_item_id')->nullable()->unique()->constrained()->restrictOnDelete());
        $this->sourceConstraint(true);
    }

    public function down(): void
    {
        if (DB::table('stock_counts')->exists()) {
            throw new RuntimeException('Stock count history exists. Restore matching code/database backups.');
        }
        $this->dropSourceConstraint();
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropUnique(['stock_count_item_id']));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_count_item_id'));
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        $this->sourceConstraint(false);
    }

    private function dropSourceConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS stock_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS stock_source_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_source_check');
        }
    }

    private function sourceConstraint(bool $counts): void
    {
        $columns = ['batch_item_id', 'sale_item_id', ...($counts ? ['stock_count_item_id'] : [])];
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
