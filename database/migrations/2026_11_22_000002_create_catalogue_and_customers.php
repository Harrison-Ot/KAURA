<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('name_key', 100)->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name');
            $table->string('name_key')->index();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('unit_label', 30);
            $table->unsignedTinyInteger('quantity_precision');
            $table->unsignedBigInteger('selling_price_minor');
            $table->unsignedBigInteger('purchase_cost_minor')->nullable();
            $table->decimal('reorder_threshold', 12, 3)->default(0);
            $table->decimal('quantity_on_hand', 12, 3)->default(0);
            $table->string('barcode', 100)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('selling_price_minor');
            $table->unsignedBigInteger('purchase_cost_minor')->nullable();
            $table->text('reason');
            $table->timestamp('effective_at');
            $table->index(['product_id', 'id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->index();
            $table->string('contact', 100);
            $table->string('contact_key', 100)->index();
            $table->unsignedBigInteger('credit_limit_minor')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->string('resource_type', 50)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['resource_type', 'resource_id']);
            $table->dropColumn(['resource_type', 'resource_id']);
        });
        Schema::dropIfExists('customers');
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
