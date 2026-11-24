<?php

namespace App\Http\Requests;

use App\Support\StockArithmetic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InstalmentRequest extends FormRequest
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
        $manual = $this->input('method') === 'manual_mpesa';

        return [
            'submission_key' => ['required', 'uuid'], 'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'method' => ['required', Rule::in(['cash', 'manual_mpesa'])], 'amount' => ['required', 'regex:'.StockArithmetic::TOTAL_RULE],
            'mpesa_code' => [$manual ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
            'expected_receiving_number' => [$manual ? 'required' : 'prohibited', 'string', 'max:20'],
            'payer_reference' => [$manual ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'handled_by' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }
}
