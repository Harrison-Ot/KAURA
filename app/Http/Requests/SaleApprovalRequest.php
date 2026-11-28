<?php

namespace App\Http\Requests;

use App\Support\CatalogueValues;
use App\Support\StockArithmetic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'], 'payment_method' => ['required', Rule::in(['cash', 'manual_mpesa'])],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'discount_amount' => ['required', 'regex:'.StockArithmetic::TOTAL_RULE], 'credit_amount' => ['required', 'regex:'.StockArithmetic::TOTAL_RULE],
            'due_on' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'], 'items.*' => ['required', 'array:product_id,quantity,quoted_price'],
            'items.*.product_id' => ['required', 'integer', 'distinct'], 'items.*.quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'items.*.quoted_price' => ['required', 'regex:'.CatalogueValues::MONEY_RULE],
        ];
    }
}
