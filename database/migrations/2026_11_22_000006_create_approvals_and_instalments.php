<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_key')->unique();
            $table->uuid('checkout_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('cart_hash', 64);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('customer_snapshot')->nullable();
            $table->json('items');
            $table->enum('payment_method', ['cash', 'manual_mpesa']);
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('credit_minor');
            $table->date('due_on')->nullable();
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'consumed'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('consumed_sale_id')->nullable()->unique()->constrained('sales')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('approval_id')->nullable()->unique()->constrained('sale_requests')->restrictOnDelete();
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->date('promised_due_on')->nullable();
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('gross_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
        });
        DB::table('sale_items')->update(['gross_minor' => DB::raw('total_minor')]);
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', ['checkout', 'instalment'])->default('checkout');
            $table->string('payload_hash', 64)->nullable();
            $table->json('allocation_notes')->nullable();
            $table->json('receipt_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('sale_requests')->exists() || DB::table('payments')->where('purpose', 'instalment')->exists()) {
            throw new RuntimeException('Approval or repayment history exists. Restore matching code/database backups.');
        }
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['purpose', 'payload_hash', 'allocation_notes', 'receipt_snapshot']));
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['gross_minor', 'discount_minor']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropUnique(['approval_id']));
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_id');
            $table->dropColumn(['credit_minor', 'promised_due_on']);
        });
        Schema::dropIfExists('sale_requests');
    }
};
