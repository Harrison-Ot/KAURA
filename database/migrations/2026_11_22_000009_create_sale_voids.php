<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->unsignedBigInteger('reversed_total_minor');
            $table->unsignedBigInteger('reversed_cost_minor')->nullable();
            $table->unsignedBigInteger('canceled_debt_minor');
            $table->unsignedBigInteger('confirmed_minor');
            $table->enum('funds_status', ['needs_review', 'not_required']);
            $table->enum('stock_status', ['pending', 'restocked', 'not_restocked'])->default('pending');
            $table->foreignId('stock_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('stock_reason')->nullable();
            $table->timestamp('stock_decided_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('sale_void_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_void_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->unique()->constrained()->restrictOnDelete();
        });
        $this->dropSource();
        Schema::table('stock_movements', fn (Blueprint $table) => $table->foreignId('sale_void_item_id')->nullable()->unique()->constrained()->restrictOnDelete());
        $this->source(true);
    }

    public function down(): void
    {
        if (DB::table('sale_voids')->exists()) {
            throw new RuntimeException('Sale void history exists. Restore matching code/database backups.');
        }
        $this->dropSource();
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropUnique(['sale_void_item_id']));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_void_item_id'));
        Schema::dropIfExists('sale_void_items');
        Schema::dropIfExists('sale_voids');
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

    private function source(bool $voids): void
    {
        $columns = ['batch_item_id', 'sale_item_id', 'stock_count_item_id', ...($voids ? ['sale_void_item_id'] : [])];
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
