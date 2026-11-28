<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VoidResolutionRequest extends FormRequest
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
        $refund = $this->input('kind') === 'refund';

        return ['submission_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['refund', 'customer_credit'])], 'recipient' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000'],
            'customer_id' => [$refund ? 'prohibited' : 'required', 'integer', 'exists:customers,id'],
            'payment_method' => [$refund ? 'required' : 'prohibited', Rule::in(['cash', 'mpesa'])],
            'payment_reference' => [$refund && $this->input('payment_method') === 'mpesa' ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
            'confirm' => ['accepted'], 'amount' => ['prohibited'], 'amount_minor' => ['prohibited'], 'actor_id' => ['prohibited'], 'business_date' => ['prohibited']];
    }
}
