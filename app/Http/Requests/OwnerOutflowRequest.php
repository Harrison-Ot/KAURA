<?php

namespace App\Http\Requests;

use App\Support\StockArithmetic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OwnerOutflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true && $this->user()->role === 'owner';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('payment_reference'))) {
            $this->merge(['payment_reference' => Str::upper(trim($this->input('payment_reference')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['replaces_outflow_id' => ['nullable', 'integer', 'exists:cash_outflows,id'], 'submission_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['expense', 'owner_withdrawal'])],
            'category' => [$this->input('kind') === 'expense' ? 'required' : 'prohibited', Rule::in(['rent', 'transport', 'electricity', 'wages', 'other'])],
            'amount' => ['required', 'regex:'.StockArithmetic::TOTAL_RULE], 'payment_method' => ['required', Rule::in(['cash', 'mpesa'])],
            'payment_reference' => [$this->input('payment_method') === 'mpesa' ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
            'recipient' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted'],
            'actor_id' => ['prohibited'], 'batch_id' => ['prohibited'], 'effective_on' => ['prohibited'], 'created_at' => ['prohibited'], 'amount_minor' => ['prohibited']];
    }
}
