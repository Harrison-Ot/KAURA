<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['submission_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'], 'items.*' => ['required', 'array:product_id,expected_version,counted_quantity'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.expected_version' => ['required', 'integer', 'min:1'],
            'items.*.counted_quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'status' => ['prohibited'], 'decided_by' => ['prohibited'], 'counted_by' => ['prohibited']];
    }
}
