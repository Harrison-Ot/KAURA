<?php

use App\Support\OutflowConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('cash_outflows', fn (Blueprint $table) => $table->foreignId('replaces_outflow_id')->nullable()->unique()->constrained('cash_outflows')->restrictOnDelete());
        Schema::table('inventory_batches', fn (Blueprint $table) => $table->foreignId('replaces_batch_id')->nullable()->unique()->constrained('inventory_batches')->restrictOnDelete());
        OutflowConstraint::restoreAfterSqliteRebuild();
    }

    public function down(): void
    {
        if (DB::table('cash_outflows')->whereNotNull('replaces_outflow_id')->exists() || DB::table('inventory_batches')->whereNotNull('replaces_batch_id')->exists()) {
            throw new RuntimeException('Linked replacement history exists. Restore matching backups.');
        }
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('cash_outflows', fn (Blueprint $table) => $table->dropUnique(['replaces_outflow_id']));
            Schema::table('cash_outflows', fn (Blueprint $table) => $table->dropConstrainedForeignId('replaces_outflow_id'));
            Schema::table('inventory_batches', fn (Blueprint $table) => $table->dropUnique(['replaces_batch_id']));
            Schema::table('inventory_batches', fn (Blueprint $table) => $table->dropConstrainedForeignId('replaces_batch_id'));
        });
        OutflowConstraint::restoreAfterSqliteRebuild();
        if (DB::getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('Foreign key verification failed after rollback. Restore the backup.');
        }
    }
};
