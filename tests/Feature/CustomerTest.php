<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_add_customer_with_zero_credit_and_attribution(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee)->get('/customers/create')->assertOk();
        $this->post('/customers', ['name' => 'Amina Builder', 'contact' => '0712 345 678'])->assertRedirect('/customers');
        $customer = Customer::query()->sole();
        $this->assertSame(0, $customer->credit_limit_minor);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'created_by' => $employee->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'customer.created', 'actor_id' => $employee->id, 'resource_id' => $customer->id]);
        $this->get('/customers?q=254712345678')->assertInertia(fn (Assert $page) => $page->component('customers/index')->has('customers.data', 1)->missing('customers.data.0.credit_limit')->missing('customers.data.0.credit_limit_minor'));
    }

    public function test_employee_cannot_set_limit_or_edit_customer_through_direct_requests(): void
    {
        $customer = $this->customer();
        $this->actingAs(User::factory()->create());
        $this->post('/customers', ['name' => 'Forged', 'contact' => '0700000000', 'credit_limit' => '50000', 'created_by' => 1])->assertSessionHasErrors(['credit_limit', 'created_by']);
        $this->get('/customers/'.$customer->id.'/edit')->assertForbidden();
        $this->patch('/customers/'.$customer->id, ['name' => 'Forged'])->assertForbidden();
        $this->patch('/customers/'.$customer->id.'/credit-limit', ['credit_limit' => '99999', 'version' => 1, 'reason' => 'Forged'])->assertForbidden();
        $this->assertSame(0, $customer->refresh()->credit_limit_minor);
        $this->assertSame('Amina Builder', $customer->name);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_matching_name_or_phone_requires_explicit_duplicate_review(): void
    {
        $this->customer();
        $this->post('/customers', ['name' => 'AMINA BUILDER', 'contact' => '0700000000'])->assertSessionHasErrors('confirm_duplicate');
        $this->post('/customers', ['name' => 'Different Name', 'contact' => '+254 712-345-678'])->assertSessionHasErrors('confirm_duplicate');
        $this->assertDatabaseCount('customers', 1);
        $this->post('/customers', ['name' => 'Different Name', 'contact' => '+254 712-345-678', 'confirm_duplicate' => true])->assertRedirect('/customers');
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_invalid_customer_contacts_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->post('/customers', ['name' => '', 'contact' => '---'])->assertSessionHasErrors('name');
        $this->post('/customers', ['name' => 'Builder', 'contact' => '---'])->assertSessionHasErrors('contact');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_owner_limit_is_exact_audited_and_stale_requests_are_rejected(): void
    {
        $customer = $this->customer();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->patch('/customers/'.$customer->id.'/credit-limit', ['credit_limit' => '10000.15', 'version' => 1, 'reason' => 'Approved customer ceiling'])->assertRedirect('/customers');
        $this->assertSame(1000015, $customer->refresh()->credit_limit_minor);
        $this->assertSame(2, $customer->version);
        $this->assertDatabaseHas('audit_events', ['event' => 'customer.credit_limit_changed', 'actor_id' => $owner->id, 'resource_id' => $customer->id, 'reason' => 'Approved customer ceiling']);
        $this->patch('/customers/'.$customer->id.'/credit-limit', ['credit_limit' => '1', 'version' => 1, 'reason' => 'Stale tab'])->assertSessionHasErrors('version');
        $this->patch('/customers/'.$customer->id.'/credit-limit', ['credit_limit' => '-1', 'version' => 2, 'reason' => 'Invalid'])->assertSessionHasErrors('credit_limit');
        $this->assertSame(1000015, $customer->refresh()->credit_limit_minor);
        $this->get('/customers')->assertInertia(fn (Assert $page) => $page->where('customers.data.0.credit_limit', '10000.15'));
        $this->get('/customers/'.$customer->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('customers/form')->where('customer.version', 2));
    }

    public function test_owner_can_edit_contact_without_changing_limit_and_with_stale_protection(): void
    {
        $customer = $this->customer();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $data = ['name' => 'Amina Building', 'contact' => '0712345678', 'version' => 1, 'reason' => 'Corrected trading name'];
        $this->patch('/customers/'.$customer->id, $data)->assertRedirect('/customers');
        $this->assertSame('Amina Building', $customer->refresh()->name);
        $this->assertSame(0, $customer->credit_limit_minor);
        $this->patch('/customers/'.$customer->id, [...$data, 'name' => 'Old tab'])->assertSessionHasErrors('version');
        $this->assertDatabaseHas('audit_events', ['event' => 'customer.updated', 'resource_id' => $customer->id]);
    }

    private function customer(): Customer
    {
        $this->actingAs(User::factory()->create())->post('/customers', ['name' => 'Amina Builder', 'contact' => '0712345678'])->assertRedirect('/customers');

        return Customer::query()->sole();
    }
}
