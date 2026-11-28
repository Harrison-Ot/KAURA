<?php

use App\Http\Controllers\CashReconciliationController;
use App\Http\Controllers\CatalogueImportController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerCreditPayoutController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\InitialPasswordController;
use App\Http\Controllers\InstalmentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OutflowController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleRequestController;
use App\Http\Controllers\SaleVoidController;
use App\Http\Controllers\ShopOperationController;
use App\Http\Controllers\ShopSettingsController;
use App\Http\Controllers\StockCountController;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('/signup', 'auth/signup')->middleware('guest')->name('signup');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('my-day', [CashReconciliationController::class, 'index'])->name('my-day');
    Route::post('my-day', [CashReconciliationController::class, 'store'])->name('my-day.store');
    Route::get('dashboard', function (Request $request) {
        $payments = Payment::query()->where('method', 'manual_mpesa');
        if ($request->user()->role !== 'owner') {
            $payments->where('handled_by', $request->user()->id);
        }

        return Inertia::render('dashboard', ['paymentAlerts' => ['pending' => (clone $payments)->where('status', 'pending')->count(), 'disputed' => (clone $payments)->where('status', 'disputed')->count()]]);
    })->name('dashboard');
    Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('sales/create', [SaleController::class, 'create'])->name('sales.create');
    Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
    Route::post('sales/{sale}/void', [SaleVoidController::class, 'store'])->name('sales.void');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/collect', [InstalmentController::class, 'create'])->name('instalments.create');
    Route::post('payments/collect', [InstalmentController::class, 'store'])->name('instalments.store');
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('sale-requests', [SaleRequestController::class, 'index'])->name('approvals.index');
    Route::post('sale-requests', [SaleRequestController::class, 'store'])->name('approvals.store');
    Route::get('sale-requests/{approval}', [SaleRequestController::class, 'show'])->name('approvals.show');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('stock-counts', [StockCountController::class, 'index'])->name('counts.index');
    Route::get('stock-counts/create', [StockCountController::class, 'create'])->name('counts.create');
    Route::post('stock-counts', [StockCountController::class, 'store'])->name('counts.store');
    Route::get('stock-counts/{count}', [StockCountController::class, 'show'])->name('counts.show');
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');

    Route::middleware('owner')->group(function () {
        Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('categories', [ProductController::class, 'category'])->name('categories.store');
        Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::patch('customers/{customer}/credit-limit', [CustomerController::class, 'creditLimit'])->name('customers.credit-limit');
    });
});

require __DIR__.'/settings.php';

Route::middleware('auth')->group(function () {
    Route::get('account/password', [InitialPasswordController::class, 'edit'])->name('account.password.edit');
    Route::put('account/password', [InitialPasswordController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');
});

Route::middleware(['auth', 'verified', 'owner'])->prefix('owner')->name('owner.')->group(function () {
    Route::get('catalogue-import', [CatalogueImportController::class, 'index'])->name('catalogue-import.index');
    Route::post('catalogue-import', [CatalogueImportController::class, 'preview'])->name('catalogue-import.preview');
    Route::post('catalogue-import/{import}/post', [CatalogueImportController::class, 'post'])->whereNumber('import')->name('catalogue-import.post');
    Route::get('operations', [ShopOperationController::class, 'index'])->name('operations.index');
    Route::get('operations/quote', [ShopOperationController::class, 'quote'])->name('operations.quote');
    Route::get('operations/create', [ShopOperationController::class, 'context'])->name('operations.create');
    Route::post('operations', [ShopOperationController::class, 'store'])->name('operations.store');
    Route::post('cash-handovers/{handover}/review', [CashReconciliationController::class, 'review'])->whereNumber('handover')->name('cash-handovers.review');
    Route::get('reports/export', [ReportExportController::class, 'export'])->name('reports.export');
    Route::post('customer-credits/{credit}/payout', [CustomerCreditPayoutController::class, 'store'])->whereNumber('credit')->name('customer-credits.payout');
    Route::get('sale-voids', [SaleVoidController::class, 'index'])->name('voids.index');
    Route::post('sale-voids/{void}/resolve', [SaleVoidController::class, 'resolve'])->name('voids.resolve');
    Route::post('sale-voids/{void}/stock', [SaleVoidController::class, 'stock'])->name('voids.stock');
    Route::get('reports/financial', [FinancialReportController::class, 'index'])->name('reports.financial');
    Route::get('reports/financial/export', [FinancialReportController::class, 'export'])->name('reports.financial.export');
    Route::get('outflows', [OutflowController::class, 'index'])->name('outflows.index');
    Route::get('outflows/create', [OutflowController::class, 'create'])->name('outflows.create');
    Route::post('outflows', [OutflowController::class, 'store'])->name('outflows.store');
    Route::get('outflows/export', [OutflowController::class, 'export'])->name('outflows.export');
    Route::get('outflows/{outflow}', [OutflowController::class, 'show'])->name('outflows.show');
    Route::post('stock-counts/{count}/decision', [StockCountController::class, 'decide'])->name('counts.decide');
    Route::post('sale-requests/{approval}/decision', [SaleRequestController::class, 'decide'])->name('approvals.decide');
    Route::post('payments/{payment}/decision', [PaymentController::class, 'decide'])->name('payments.decide');
    Route::get('shop-settings', [ShopSettingsController::class, 'edit'])->name('shop-settings.edit');
    Route::patch('shop-settings', [ShopSettingsController::class, 'update'])->name('shop-settings.update');
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/create', [InventoryController::class, 'create'])->name('inventory.create');
    Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
    Route::get('inventory/template', [InventoryController::class, 'template'])->name('inventory.template');
    Route::get('inventory/ledger', [InventoryController::class, 'stockLedger'])->name('inventory.ledger');
    Route::post('inventory/complete-opening', [InventoryController::class, 'completeOpening'])->name('inventory.complete-opening');
    Route::get('inventory/{batch}', [InventoryController::class, 'show'])->name('inventory.show');
    Route::post('inventory/{batch}/post', [InventoryController::class, 'post'])->name('inventory.post');
    Route::post('inventory/{batch}/cancel', [InventoryController::class, 'cancel'])->name('inventory.cancel');
    Route::get('customers/{customer}/statement', [InventoryController::class, 'statement'])->name('customers.statement');
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::get('audit', [EmployeeController::class, 'audit'])->name('audit.index');
});
