<?php

namespace App\Http\Requests;

use App\Support\CatalogueValues;
use App\Support\StockArithmetic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('mpesa_code'))) {
            $this->merge(['mpesa_code' => Str::upper(trim($this->input('mpesa_code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $manual = $this->input('payment_method') === 'manual_mpesa';

        return [
            'submission_key' => ['required', 'uuid'], 'payment_method' => ['required', Rule::in(['cash', 'manual_mpesa'])],
            'cash_tendered' => [$manual ? 'prohibited' : 'required', 'regex:'.StockArithmetic::TOTAL_RULE],
            'mpesa_code' => [$manual ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
            'mpesa_amount' => [$manual ? 'required' : 'prohibited', 'regex:'.StockArithmetic::TOTAL_RULE],
            'expected_receiving_number' => [$manual ? 'required' : 'prohibited', 'string', 'max:20'],
            'payer_reference' => [$manual ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'approval_id' => ['nullable', 'integer', 'exists:sale_requests,id'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity,quoted_price'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'items.*.quoted_price' => ['required', 'regex:'.CatalogueValues::MONEY_RULE],
            'discount' => ['prohibited'], 'discount_minor' => ['prohibited'], 'credit_amount' => ['prohibited'],
            'sold_by' => ['prohibited'], 'handled_by' => ['prohibited'], 'total' => ['prohibited'],
            'status' => ['prohibited'], 'verified_by' => ['prohibited'], 'receiving_number' => ['prohibited'],
        ];
    }
}
