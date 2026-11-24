<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $editing = $this->route('customer') instanceof Customer;

        return [
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:100'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            'version' => [$editing ? 'required' : 'prohibited', 'integer', 'min:1'],
            'reason' => [$editing ? 'required' : 'nullable', 'string', 'max:1000'],
            'credit_limit' => ['prohibited'],
            'credit_limit_minor' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }
}
