<?php

namespace Database\Seeders;

use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\AccessAudit;
use App\Services\CatalogueService;
use App\Services\CheckoutService;
use App\Services\CustomerCreditPayoutService;
use App\Services\CustomerService;
use App\Services\InstalmentService;
use App\Services\InventoryService;
use App\Services\OwnerOutflowService;
use App\Services\PaymentVerificationService;
use App\Services\SaleApprovalService;
use App\Services\SaleVoidService;
use App\Services\StockCountService;
use App\Services\VoidResolutionService;
use App\Support\CatalogueValues;
use App\Support\StockArithmetic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevelopmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo shop data may only be seeded in local or testing environments.');
        }
        DB::transaction(function (): void {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            if (AuditEvent::query()->where('event', 'development.demo_seeded')->exists()) {
                $this->command?->info('Demo data already exists. Current balances, edits and passwords were preserved.');

                return;
            }
            foreach (['inventory_batches', 'stock_movements', 'receivables', 'sales', 'payments', 'cash_outflows', 'sale_requests', 'stock_counts'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new \RuntimeException('Demo seeding refuses an established shop. Use a separate development database; no records were changed.');
                }
            }
            if ($settings->opening_completed_at !== null || Product::query()->where('quantity_on_hand', '!=', 0)->exists() || Product::query()->where('sku', 'like', 'DEMO-%')->exists()) {
                throw new \RuntimeException('Demo seeding requires an unposted shop with no existing DEMO SKUs.');
            }
            (new DevelopmentUserSeeder)->run();
            $owner = User::query()->where('email', 'owner@kaura.test')->sole();
            $employee = User::query()->where('email', 'employee@kaura.test')->sole();
            if (! $owner->is_active || $owner->role !== 'owner' || ! $employee->is_active || $employee->role !== 'employee') {
                throw new \RuntimeException('Existing development accounts have different permissions. No accounts were reset.');
            }
            $settings->fill(['shop_name' => $settings->shop_name ?? 'Kaura Hardware — DEMO', 'mpesa_number' => $settings->mpesa_number ?? 'DEMO-NOT-PAYABLE']);
            $settings->version++;
            $settings->save();
            AccessAudit::record('development.demo_setup', $owner, null, 'DEMO ONLY: fictional testing fixtures; never send real funds.', null, $settings->only(['shop_name', 'mpesa_number']), 'shop_settings', 1);
            $categories = [];
            foreach (['Building', 'Tools', 'Plumbing', 'Electrical', 'Paint'] as $name) {
                $categories[$name] = Category::firstOrCreate(['name_key' => CatalogueValues::nameKey('DEMO '.$name)], ['name' => 'DEMO '.$name]);
            }
            $products = [];
            $rows = [];
            foreach ($this->catalogue() as [$sku, $name, $category, $unit, $precision, $cost, $price, $quantity]) {
                $product = app(CatalogueService::class)->save($owner, ['sku' => 'DEMO-'.$sku, 'name' => '[DEMO] '.$name, 'category_id' => $categories[$category]->id, 'unit_label' => $unit, 'quantity_precision' => $precision, 'purchase_cost' => $cost, 'selling_price' => $price, 'reorder_threshold' => '3', 'is_active' => true]);
                $products[$sku] = $product;
                $rows[] = ['sku' => $product->sku, 'unit' => $unit, 'quantity' => $quantity, 'unit_cost' => $cost];
            }
            // Existing zero-stock catalogue entries remain zero; the opening record is explicitly DEMO, not a real count.
            foreach (Product::query()->where('is_active', true)->where('sku', 'not like', 'DEMO-%')->get() as $product) {
                $rows[] = ['sku' => $product->sku, 'unit' => $product->unit_label, 'quantity' => '0', 'unit_cost' => null];
            }
            $customers = [];
            foreach (['Jane Builder', 'Otieno Workshop', 'Amina Renovations', 'Mwangi Repairs', 'Njeri Home', 'Kamau Projects'] as $index => $name) {
                $customer = app(CustomerService::class)->save($owner, ['name' => '[DEMO] '.$name, 'contact' => 'DEMO-CUSTOMER-'.($index + 1)]);
                app(CustomerService::class)->setCreditLimit($owner, $customer, '20000.00', $customer->version, 'DEMO credit limit for testing');
                $customers[] = $customer->refresh();
            }
            $inventory = app(InventoryService::class);
            $asOf = now('Africa/Nairobi')->subDays(7)->toDateString();
            $stock = $inventory->draft($owner, ['submission_key' => (string) Str::uuid(), 'kind' => 'opening_stock', 'effective_on' => $asOf, 'source_note' => 'DEMO ONLY: simulated opening stock; existing catalogue zero balances remain zero.', 'rows' => $rows]);
            $inventory->post($owner, $stock, 'DEMO simulated owner approval');
            $debt = $inventory->draft($owner, ['submission_key' => (string) Str::uuid(), 'kind' => 'opening_debt', 'effective_on' => $asOf, 'source_note' => 'DEMO ONLY: fictional paper debt', 'rows' => [
                ['customer_id' => $customers[0]->id, 'amount' => '1500.00', 'due_on' => now('Africa/Nairobi')->subDays(2)->toDateString()],
                ['customer_id' => $customers[1]->id, 'amount' => '800.00', 'due_on' => now('Africa/Nairobi')->addDays(7)->toDateString()],
                ['customer_id' => $customers[2]->id, 'amount' => '300.00', 'due_on' => $asOf],
            ]]);
            $inventory->post($owner, $debt, 'DEMO simulated owner approval');
            $inventory->completeOpening($owner, '2600.00', 'DEMO ONLY: simulated opening sign-off for testing, not real shop balances.', $asOf);
            foreach ([['CEMENT', '2'], ['NAILS', '1.500'], ['HAMMER', '1'], ['GLOVES', '2'], ['PADLOCK', '1'], ['PVC', '3']] as $index => [$sku, $quantity]) {
                $this->sale($employee, $products[$sku], $quantity, $customers[$index]);
            }
            $this->sale($owner, $products['CABLE'], '10.500', $customers[0]);
            foreach ([['IRON', '1', 'pending'], ['PAINT', '1', 'verified'], ['TAP', '2', 'disputed']] as $index => [$sku, $quantity, $status]) {
                $sale = $this->sale($employee, $products[$sku], $quantity, $customers[$index + 1], 'DEMOCLAIM00'.($index + 1));
                if ($status !== 'pending') {
                    app(PaymentVerificationService::class)->decide($owner, Payment::query()->where('sale_id', $sale->id)->sole(), $status, 'DEMO simulated statement review');
                }
            }
            $approvalService = app(SaleApprovalService::class);
            foreach (['consumed', 'pending', 'approved', 'rejected'] as $index => $status) {
                $product = $products[$status === 'consumed' ? 'DRILL' : 'WHEELBARROW'];
                $request = $approvalService->request($employee, ['submission_key' => (string) Str::uuid(), 'customer_id' => $customers[$index]->id, 'payment_method' => 'cash', 'discount_amount' => '300.00', 'credit_amount' => '2000.00', 'due_on' => now('Africa/Nairobi')->addDays(7)->toDateString(), 'reason' => 'DEMO proposed discount and promised repayment terms', 'items' => $this->items($product, '1')]);
                if ($status !== 'pending') {
                    $approvalService->decide($owner, $request, $status === 'rejected' ? 'rejected' : 'approved', 'DEMO owner decision for testing', 30);
                }
                if ($status === 'consumed') {
                    app(CheckoutService::class)->checkout($employee, ['submission_key' => $request->checkout_key, 'approval_id' => $request->id, 'customer_id' => $customers[$index]->id, 'payment_method' => 'cash', 'cash_tendered' => CatalogueValues::money($product->selling_price_minor - 30000 - 200000), 'items' => $this->items($product, '1')]);
                }
            }
            app(InstalmentService::class)->collect($employee, ['submission_key' => (string) Str::uuid(), 'customer_id' => $customers[0]->id, 'amount' => '500.00', 'method' => 'cash']);
            foreach ([1, 2] as $index) {
                $payment = app(InstalmentService::class)->collect($employee, ['submission_key' => (string) Str::uuid(), 'customer_id' => $customers[$index]->id, 'amount' => '100.00', 'method' => 'manual_mpesa', 'mpesa_code' => 'DEMOINST00'.$index, 'expected_receiving_number' => $settings->mpesa_number]);
                if ($index === 2) {
                    app(PaymentVerificationService::class)->decide($owner, $payment, 'verified', 'DEMO verified instalment');
                }
            }
            foreach (['pending', 'refund', 'customer_credit', 'paid_credit'] as $index => $kind) {
                $sale = $this->sale($employee, $products['PADLOCK'], '1', $customers[$index]);
                $void = app(SaleVoidService::class)->void($employee, $sale, 'DEMO canceled order for review');
                if ($kind === 'pending') {
                    continue;
                }
                app(SaleVoidService::class)->decideStock($owner, $void, 'restocked', 'DEMO all goods physically returned and resellable');
                $resolution = app(VoidResolutionService::class)->resolve($owner, $void, ['submission_key' => (string) Str::uuid(), 'kind' => $kind === 'refund' ? 'refund' : 'customer_credit', ...($kind === 'refund' ? ['payment_method' => 'cash'] : ['customer_id' => $customers[$index]->id]), 'recipient' => $customers[$index]->name, 'reason' => 'DEMO fictional funds disposition']);
                if ($kind === 'paid_credit') {
                    $creditId = (int) DB::table('customer_credits')->where('void_resolution_id', $resolution->id)->value('id');
                    app(CustomerCreditPayoutService::class)->pay($owner, $creditId, ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'recipient' => $customers[$index]->name, 'reason' => 'DEMO fictional retained credit payout']);
                }
            }
            $delivery = $inventory->draft($owner, ['submission_key' => (string) Str::uuid(), 'kind' => 'stock_receipt', 'effective_on' => now('Africa/Nairobi')->subDay()->toDateString(), 'source_note' => 'DEMO paid supplier delivery', 'supplier_name' => '[DEMO] Building Supplies', 'delivery_reference' => 'DEMODELV001', 'payment_method' => 'cash', 'amount_paid' => '8575.00', 'rows' => [['sku' => 'DEMO-CEMENT', 'unit' => 'bag', 'quantity' => '10', 'unit_cost' => '730.00'], ['sku' => 'DEMO-PVC', 'unit' => 'piece', 'quantity' => '5', 'unit_cost' => '255.00']]]);
            $inventory->post($owner, $delivery, 'DEMO paid delivery approval');
            foreach ([['rent', '3500.00', 'cash'], ['transport', '250.00', 'cash'], ['electricity', '450.00', 'mpesa']] as [$category, $amount, $method]) {
                app(OwnerOutflowService::class)->record($owner, ['submission_key' => (string) Str::uuid(), 'kind' => 'expense', 'category' => $category, 'amount' => $amount, 'payment_method' => $method, ...($method === 'mpesa' ? ['payment_reference' => 'DEMOOUT001'] : []), 'recipient' => '[DEMO] '.$category.' provider', 'reason' => 'DEMO fictional operating expense']);
            }
            app(OwnerOutflowService::class)->record($owner, ['submission_key' => (string) Str::uuid(), 'kind' => 'owner_withdrawal', 'amount' => '1000.00', 'payment_method' => 'cash', 'recipient' => 'Development Owner', 'reason' => 'DEMO fictional withdrawal']);
            foreach (['SCREWS', 'CEMENT'] as $index => $sku) {
                $product = $products[$sku]->refresh();
                $count = app(StockCountService::class)->submit($employee, ['submission_key' => (string) Str::uuid(), 'reason' => 'DEMO shelf discrepancy for testing', 'items' => [['product_id' => $product->id, 'expected_version' => $product->version, 'counted_quantity' => (string) ((int) $product->quantity_on_hand - 1)]]]);
                if ($index === 0) {
                    app(StockCountService::class)->decide($owner, $count, 'approved', 'DEMO reviewed count');
                }
            }
            AccessAudit::record('development.demo_seeded', $owner, null, 'DEMO ONLY: fictional dataset seeded once through normal posting services.', null, ['products' => count($products), 'customers' => count($customers)]);
            $this->command?->info('Demo shop ready: '.count($products).' demo products, 6 customers, sales, approvals, collections, voids, refunds, credits and counts.');
        });
    }

    /** @return list<array{string, string, string, string, int, string|null, string, string}> */
    private function catalogue(): array
    {
        return [
            ['CEMENT', 'Cement 50kg', 'Building', 'bag', 0, '720.00', '850.00', '40'],
            ['NAILS', 'Common nails', 'Building', 'kg', 3, '160.00', '220.00', '80.500'],
            ['IRON', 'Roofing sheet 3m', 'Building', 'piece', 0, '1200.00', '1550.00', '30'],
            ['PAINT', 'Interior paint 4L', 'Paint', 'tin', 0, '1350.00', '1850.00', '20'],
            ['HAMMER', 'Claw hammer', 'Tools', 'piece', 0, '350.00', '550.00', '20'],
            ['PADLOCK', 'Security padlock', 'Tools', 'piece', 0, '450.00', '650.00', '20'],
            ['TAP', 'Brass tap', 'Plumbing', 'piece', 0, '250.00', '400.00', '25'],
            ['CABLE', 'Electrical cable', 'Electrical', 'metre', 3, '42.00', '65.00', '200.000'],
            ['PVC', 'PVC pipe 3m', 'Plumbing', 'piece', 0, '250.00', '350.00', '30'],
            ['SCREWS', 'Wood screws pack 20', 'Building', 'pack', 0, '80.00', '120.00', '30'],
            ['SAND', 'Building sand', 'Building', 'kg', 3, '4.00', '7.00', '300.000'],
            ['GLOVES', 'Work gloves', 'Tools', 'pair', 0, '80.00', '130.00', '40'],
            ['ELBOW', 'PVC elbow', 'Plumbing', 'piece', 0, '35.00', '60.00', '50'],
            ['ROOFNAIL', 'Roofing nails', 'Building', 'kg', 3, '180.00', '260.00', '30.000'],
            ['GLUE', 'Wood glue 500ml', 'Paint', 'bottle', 0, '200.00', '300.00', '15'],
            ['MASK', 'Dust mask', 'Tools', 'piece', 0, '25.00', '50.00', '60'],
            ['WHEELBARROW', 'Wheelbarrow', 'Tools', 'piece', 0, '3800.00', '4800.00', '2'],
            ['DRILL', 'Electric drill', 'Tools', 'piece', 0, '4500.00', '6500.00', '2'],
            ['SOCKET', 'Twin socket', 'Electrical', 'piece', 0, '220.00', '350.00', '20'],
            ['SWITCH', 'Light switch', 'Electrical', 'piece', 0, '90.00', '150.00', '30'],
            ['BRUSH', 'Paint brush 3 inch', 'Paint', 'piece', 0, '100.00', '180.00', '30'],
            ['ROLLER', 'Paint roller', 'Paint', 'piece', 0, '280.00', '450.00', '10'],
            ['UNKNOWN', 'Door handle — unknown opening cost', 'Tools', 'piece', 0, null, '350.00', '10'],
            ['EMPTY', 'Out-of-stock sample', 'Tools', 'piece', 0, '75.00', '100.00', '0'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(Product $product, string $quantity): array
    {
        return [['product_id' => $product->id, 'quantity' => $quantity, 'quoted_price' => CatalogueValues::money($product->selling_price_minor)]];
    }

    private function sale(User $actor, Product $product, string $quantity, Customer $customer, ?string $code = null): Sale
    {
        $amount = CatalogueValues::money(StockArithmetic::lineValue(StockArithmetic::quantity($quantity, $product->quantity_precision, 'quantity'), $product->selling_price_minor));

        return app(CheckoutService::class)->checkout($actor, ['submission_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'payment_method' => $code === null ? 'cash' : 'manual_mpesa', ...($code === null ? ['cash_tendered' => $amount] : ['mpesa_code' => $code, 'mpesa_amount' => $amount, 'expected_receiving_number' => ShopSettings::query()->findOrFail(1)->mpesa_number]), 'items' => $this->items($product, $quantity)]);
    }
}
