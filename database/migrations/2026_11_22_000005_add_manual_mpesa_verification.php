<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('checkout_method', ['cash', 'manual_mpesa'])->default('cash');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payer_reference', 255)->nullable();
        });
        Schema::table('receivables', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::create('payment_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->enum('decision', ['verified', 'disputed']);
            $table->text('reason');
            $table->timestamp('created_at');
        });
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('receivable_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['payment_id', 'receivable_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->where('method', 'manual_mpesa')->exists() || DB::table('receivables')->whereNotNull('sale_id')->exists() || DB::table('payment_allocations')->exists() || DB::table('payment_verifications')->exists()) {
            throw new RuntimeException('Payment history exists. Restore matching code/database backups instead of dropping verification records.');
        }
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_verifications');
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropUnique(['sale_id']);
        });
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('payer_reference'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('checkout_method'));
    }
};
