<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_credit_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_outflow_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('recipient');
            $table->text('reason');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('customer_credit_payouts')->exists()) {
            throw new RuntimeException('Customer credit payout history exists. Restore matching code/database backups.');
        }
        Schema::dropIfExists('customer_credit_payouts');
    }
};
