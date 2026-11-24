<?php

namespace App\Http\Requests;

use App\Support\CatalogueValues;
use App\Support\StockArithmetic;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    protected function prepareForValidation(): void
    {
        $kind = $this->input('kind');
        if (! in_array($kind, ['opening_stock', 'opening_debt', 'stock_receipt'], true)) {
            return;
        }
        $file = $this->file('csv');
        if ($file instanceof UploadedFile && $file->isValid()) {
            if ($this->filled('rows') || $file->getSize() > 1024 * 1024) {
                throw ValidationException::withMessages(['csv' => 'Upload one CSV up to 1 MB, without manual rows.']);
            }
            $stream = fopen($file->getRealPath(), 'r');
            if ($stream === false) {
                throw ValidationException::withMessages(['csv' => 'The CSV could not be read.']);
            }
            try {
                $headers = fgetcsv($stream, 0, ',', '"', '');
                $expected = $kind === 'opening_debt' ? ['customer_id', 'amount', 'due_on'] : ['sku', 'unit', 'quantity', 'unit_cost'];
                if ($headers !== false) {
                    $headers = array_map(fn (?string $header): string => trim((string) $header), $headers);
                    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
                }
                if ($headers !== $expected) {
                    throw ValidationException::withMessages(['csv' => 'Use these CSV headers in order: '.implode(',', $expected)]);
                }
                $rows = [];
                while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                    if ($values === [null]) {
                        continue;
                    }
                    if (count($values) !== count($expected) || count($rows) >= 500) {
                        throw ValidationException::withMessages(['csv' => 'CSV rows must match the headers and cannot exceed 500 rows.']);
                    }
                    if (! mb_check_encoding(implode('', $values), 'UTF-8')) {
                        throw ValidationException::withMessages(['csv' => 'Save the CSV with UTF-8 text encoding.']);
                    }
                    $values = array_map(fn (?string $value): ?string => trim((string) $value) === '' ? null : trim((string) $value), $values);
                    $rows[] = array_combine($expected, $values);
                }
                $this->merge(['rows' => $rows]);
            } finally {
                fclose($stream);
            }
        }
        $rows = $this->input('rows');
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                if (is_array($row) && isset($row['sku']) && is_string($row['sku'])) {
                    $row['sku'] = Str::upper(trim($row['sku']));
                }
            }
            unset($row);
            $this->merge(['rows' => $rows]);
        }
        foreach (['delivery_reference', 'payment_reference'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Str::upper(trim($this->input($field)))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $debt = $this->input('kind') === 'opening_debt';
        $receipt = $this->input('kind') === 'stock_receipt';

        return [
            'kind' => ['required', Rule::in(['opening_stock', 'opening_debt', 'stock_receipt'])],
            'submission_key' => ['required', 'uuid'],
            'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('Africa/Nairobi')->toDateString()],
            'source_note' => ['required', 'string', 'max:2000'],
            'csv' => ['nullable', 'file', 'max:1024', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'rows' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'rows.*' => ['required', 'array:sku,unit,quantity,unit_cost,customer_id,amount,due_on'],
            'rows.*.sku' => [$debt ? 'prohibited' : 'required', 'string', 'max:50', 'distinct'],
            'rows.*.unit' => [$debt ? 'prohibited' : 'required', 'string', 'max:30'],
            'rows.*.quantity' => [$debt ? 'prohibited' : 'required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'rows.*.unit_cost' => [$debt ? 'prohibited' : ($receipt ? 'required' : 'nullable'), 'regex:'.CatalogueValues::MONEY_RULE],
            'rows.*.customer_id' => [$debt ? 'required' : 'prohibited', 'integer', 'exists:customers,id', 'distinct'],
            'rows.*.amount' => [$debt ? 'required' : 'prohibited', 'regex:'.CatalogueValues::MONEY_RULE],
            'rows.*.due_on' => [$debt ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'supplier_name' => [$receipt ? 'required' : 'prohibited', 'string', 'max:255'],
            'delivery_reference' => [$receipt ? 'required' : 'prohibited', 'string', 'max:100'],
            'amount_paid' => [$receipt ? 'required' : 'prohibited', 'regex:'.StockArithmetic::TOTAL_RULE],
            'payment_method' => [$receipt ? 'required' : 'prohibited', Rule::in(['cash', 'mpesa'])],
            'payment_reference' => [$receipt && $this->input('payment_method') === 'mpesa' ? 'required' : 'prohibited', 'string', 'max:100', 'regex:/^[A-Z0-9]+$/'],
        ];
    }
}
