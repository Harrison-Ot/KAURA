<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_product_with_exact_money_zero_stock_and_initial_history(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/products', $this->data(['sku' => 'cement-bag', 'selling_price' => '999999999.99', 'purchase_cost' => '0.01']))->assertRedirect('/products');
        $product = Product::query()->sole();
        $this->assertSame('CEMENT-BAG', $product->sku);
        $this->assertSame(99999999999, $product->selling_price_minor);
        $this->assertSame(1, $product->purchase_cost_minor);
        $this->assertSame('0.000', $product->quantity_on_hand);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $product->id, 'actor_id' => $owner->id, 'selling_price_minor' => 99999999999]);
        $this->assertDatabaseHas('audit_events', ['event' => 'product.created', 'resource_type' => 'product', 'resource_id' => $product->id]);
    }

    public function test_employees_can_search_products_but_cannot_see_cost_or_mutate_catalogue(): void
    {
        $product = $this->product();
        $this->actingAs(User::factory()->create())->get('/products?q=cement')->assertInertia(fn (Assert $page) => $page->component('products/index')->has('products.data', 1)->where('products.data.0.selling_price', '850.50')->missing('products.data.0.purchase_cost')->missing('products.data.0.purchase_cost_minor'));
        $this->get('/products/create')->assertForbidden();
        $this->get('/products/'.$product->id.'/edit')->assertForbidden();
        $this->post('/products', $this->data(['sku' => 'OTHER']))->assertForbidden();
        $this->patch('/products/'.$product->id, $this->data(['selling_price' => '1.00', 'version' => 1, 'reason' => 'Forged']))->assertForbidden();
        $this->post('/categories', ['name' => 'Forged'])->assertForbidden();
        $this->assertSame(85050, $product->refresh()->selling_price_minor);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_guests_cannot_read_catalogue_or_customers(): void
    {
        $this->get('/products')->assertRedirect('/login');
        $this->get('/customers')->assertRedirect('/login');
        $this->post('/customers', ['name' => 'Customer', 'contact' => '0712345678'])->assertRedirect('/login');
    }

    public function test_repeated_product_submit_and_barcode_duplicates_do_not_create_second_listing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $data = $this->data(['barcode' => '123456789']);
        $this->post('/products', $data)->assertRedirect('/products');
        $this->post('/products', $data)->assertSessionHasErrors(['sku', 'barcode']);
        $this->post('/products', [...$data, 'sku' => 'SECOND', 'name' => 'Different product'])->assertSessionHasErrors('barcode');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_price_history', 1);
    }

    public function test_stock_fields_invalid_money_and_excess_quantity_precision_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->post('/products', $this->data(['quantity_on_hand' => '200', 'stock_quantity' => 5, 'selling_price' => '1.234', 'purchase_cost' => '-1', 'reorder_threshold' => '0.5']))->assertSessionHasErrors(['quantity_on_hand', 'stock_quantity', 'selling_price', 'purchase_cost', 'reorder_threshold']);
        $this->post('/products', $this->data(['selling_price' => '1e5', 'category_id' => 99999]))->assertSessionHasErrors(['selling_price', 'category_id']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_price_history', 0);
    }

    public function test_unknown_cost_stays_null_and_bag_and_kilogram_are_independent(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->post('/products', $this->data(['purchase_cost' => '']))->assertRedirect('/products');
        $this->post('/products', $this->data(['sku' => 'CEMENT-KG', 'unit_label' => 'kg', 'quantity_precision' => 3, 'reorder_threshold' => '1.125', 'confirm_duplicate' => true]))->assertRedirect('/products');
        $bag = Product::query()->where('sku', 'CEMENT-BAG')->firstOrFail();
        $kg = Product::query()->where('sku', 'CEMENT-KG')->firstOrFail();
        $this->assertNull($bag->purchase_cost_minor);
        $this->assertSame('1.125', $kg->reorder_threshold);
        $this->patch('/products/'.$bag->id, $this->data(['selling_price' => '900', 'purchase_cost' => '', 'version' => 1, 'reason' => 'Supplier pricing changed']))->assertRedirect('/products');
        $this->assertSame(85050, $kg->refresh()->selling_price_minor);
        $this->assertSame('0.000', $kg->quantity_on_hand);
        $this->assertSame('0.000', $bag->refresh()->quantity_on_hand);
    }

    public function test_same_name_requires_review_before_creation(): void
    {
        $this->product();
        $this->actingAs(User::factory()->create(['role' => 'owner']))->post('/products', $this->data(['sku' => 'SECOND']))->assertSessionHasErrors('confirm_duplicate');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_prices_are_snapshotted_and_stale_edits_cannot_overwrite_them(): void
    {
        $product = $this->product();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->patch('/products/'.$product->id, $this->data(['selling_price' => '900.25', 'purchase_cost' => '700.10', 'version' => 1, 'reason' => 'Supplier price revision']))->assertRedirect('/products');
        $this->assertSame(2, $product->refresh()->version);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $product->id, 'selling_price_minor' => 85050, 'purchase_cost_minor' => 65025]);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $product->id, 'selling_price_minor' => 90025, 'purchase_cost_minor' => 70010]);
        $this->patch('/products/'.$product->id, $this->data(['selling_price' => '1', 'version' => 1, 'reason' => 'Stale tab']))->assertSessionHasErrors('version');
        $this->assertSame(90025, $product->refresh()->selling_price_minor);
        $this->assertDatabaseCount('product_price_history', 2);
        $this->get('/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('products/form')->where('product.version', 2)->has('history', 2)->where('history.0.selling_price', '900.25'));
    }

    public function test_unit_identity_is_fixed_and_deactivation_preserves_history(): void
    {
        $product = $this->product();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->patch('/products/'.$product->id, $this->data(['sku' => 'NEW-SKU', 'unit_label' => 'kg', 'quantity_precision' => 3, 'version' => 1, 'reason' => 'Change unit']))->assertSessionHasErrors(['sku', 'unit_label', 'quantity_precision']);
        $this->patch('/products/'.$product->id, $this->data(['is_active' => false, 'version' => 1, 'reason' => 'No longer stocked']))->assertRedirect('/products');
        $this->assertFalse($product->refresh()->is_active);
        $this->get('/products')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
        $this->get('/products?status=inactive')->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.is_active', false));
        $this->assertDatabaseCount('product_price_history', 1);
    }

    public function test_search_filters_low_stock_and_pagination_work_together(): void
    {
        $product = $this->product();
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        for ($i = 1; $i <= 21; $i++) {
            Product::create(['sku' => 'TOOL-'.$i, 'name' => 'Tool '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'name_key' => 'tool '.$i, 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 100, 'reorder_threshold' => '0']);
        }
        $this->actingAs(User::factory()->create())->get('/products?q=tool&category='.$category->id.'&low_stock=1')->assertInertia(fn (Assert $page) => $page->has('products.data', 20)->where('products.total', 21)->where('products.last_page', 2));
        $this->get('/products?q=tool&category='.$category->id.'&page=2')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->get('/products?q=cement-bag')->assertInertia(fn (Assert $page) => $page->where('products.data.0.id', $product->id));
        $this->get('/products?q=%25')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    }

    public function test_category_names_are_case_insensitively_unique_and_audited(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/categories', ['name' => 'Hand Tools'])->assertRedirect();
        $this->post('/categories', ['name' => 'HAND   TOOLS'])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseHas('audit_events', ['event' => 'category.created', 'actor_id' => $owner->id]);
    }

    public function test_catalogue_and_history_roll_back_when_audit_cannot_be_written(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit storage unavailable');
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/products', $this->data());
            $this->fail('Expected the audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_price_history', 0);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        $category = Category::query()->first() ?? Category::create(['name' => 'Building materials', 'name_key' => 'building materials']);

        return [...['sku' => 'CEMENT-BAG', 'name' => 'Cement', 'category_id' => $category->id, 'unit_label' => 'bag', 'quantity_precision' => 0, 'selling_price' => '850.50', 'purchase_cost' => '650.25', 'reorder_threshold' => '5', 'is_active' => true], ...$overrides];
    }

    private function product(): Product
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/products', $this->data())->assertRedirect('/products');

        return Product::query()->sole();
    }
}
