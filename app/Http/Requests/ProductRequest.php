<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\CatalogueValues;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => Str::upper(trim($this->input('sku')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $product = $product instanceof Product ? $product : null;
        $precision = max(0, min(3, (int) $this->input('quantity_precision')));
        $quantityPattern = $precision === 0 ? '/^\d{1,9}$/' : '/^\d{1,9}(?:\.\d{1,'.$precision.'})?$/';

        return [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9._\/-]*$/', Rule::unique('products', 'sku')->ignore($product?->id), ...($product ? [Rule::in([$product->sku])] : [])],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit_label' => ['required', 'string', 'max:30', ...($product ? [Rule::in([$product->unit_label])] : [])],
            'quantity_precision' => ['required', 'integer', 'between:0,3', ...($product ? [Rule::in([$product->quantity_precision])] : [])],
            'selling_price' => ['required', 'regex:'.CatalogueValues::MONEY_RULE],
            'purchase_cost' => ['nullable', 'regex:'.CatalogueValues::MONEY_RULE],
            'reorder_threshold' => ['required', 'regex:'.$quantityPattern],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($product?->id)],
            'is_active' => ['required', 'boolean'],
            'version' => [$product ? 'required' : 'prohibited', 'integer', 'min:1'],
            'reason' => [$product ? 'required' : 'nullable', 'string', 'max:1000'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            'quantity_on_hand' => ['prohibited'],
            'stock_quantity' => ['prohibited'],
            'selling_price_minor' => ['prohibited'],
            'purchase_cost_minor' => ['prohibited'],
            'inventory_value_minor' => ['prohibited'],
            'average_cost_minor' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'selling_price.regex' => 'Enter a nonnegative KES amount with up to 9 whole digits and 2 decimal places.',
            'purchase_cost.regex' => 'Enter a nonnegative KES amount with up to 2 decimal places, or leave unknown costs blank.',
            'reorder_threshold.regex' => 'Use a nonnegative quantity within the selected unit precision (up to 9 whole digits).',
            'sku.in' => 'SKU identifies this listing. Create a separate listing for a different SKU.',
            'unit_label.in' => 'Create a separate listing for a different selling unit.',
            'quantity_precision.in' => 'Quantity precision is fixed for this listing.',
        ];
    }
}
