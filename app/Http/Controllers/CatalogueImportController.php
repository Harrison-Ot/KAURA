<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ShopSettings;
use App\Services\AccessAudit;
use App\Services\CatalogueService;
use App\Services\PostingTransaction;
use App\Support\CatalogueValues;
use App\Support\StockArithmetic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogueImportController extends Controller
{
    private const HEADERS = ['sku', 'name', 'category', 'unit', 'precision', 'selling_price', 'purchase_cost', 'reorder_threshold', 'barcode'];

    public function index(Request $request): Response
    {
        $data = $request->validate(['import' => ['nullable', 'integer', 'exists:catalogue_imports,id']]);

        return Inertia::render('products/import', ['submissionKey' => (string) Str::uuid(), 'headers' => implode(',', self::HEADERS), 'draft' => empty($data['import']) ? null : DB::table('catalogue_imports')->find($data['import']), 'recent' => DB::table('catalogue_imports')->latest('id')->limit(10)->get(['id', 'source_note', 'posted_at', 'created_at'])]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $data = $request->validate(['submission_key' => ['required', 'uuid'], 'source_note' => ['required', 'string', 'max:2000'], 'csv' => ['required', 'file', 'max:1024']]);
        $file = $request->file('csv');
        if (! $file instanceof UploadedFile) {
            abort(422);
        }
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            abort(422);
        }
        try {
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if (is_array($headers)) {
                $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            }
            if ($headers !== self::HEADERS) {
                throw ValidationException::withMessages(['csv' => 'Use the exact headers shown on the import screen.']);
            }
            $rows = [];
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($rows) >= 500 || count($values) !== count(self::HEADERS) || ! mb_check_encoding(implode('', $values), 'UTF-8')) {
                    throw ValidationException::withMessages(['csv' => 'Use UTF-8 CSV with up to 500 rows and matching columns.']);
                }
                $row = array_combine(self::HEADERS, array_map(fn (?string $value): string => trim((string) $value), $values));
                $row['sku'] = Str::upper($row['sku']);
                $rows[] = $row;
            }
            $this->validateRows($rows);
        } finally {
            fclose($stream);
        }
        $id = PostingTransaction::run(function () use ($rows, $data, $request): int {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $hash = hash('sha256', json_encode([$rows, $data['source_note']], JSON_THROW_ON_ERROR));
            $existing = DB::table('catalogue_imports')->where('submission_key', $data['submission_key'])->first();
            if ($existing !== null) {
                if ($existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['csv' => 'This submission key already belongs to a different import.']);
                }

                return (int) $existing->id;
            }
            $id = DB::table('catalogue_imports')->insertGetId(['submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'rows' => json_encode($rows, JSON_THROW_ON_ERROR), 'source_note' => $data['source_note'], 'actor_id' => $request->user()->id, 'created_at' => now()]);
            AccessAudit::record('catalogue.import_previewed', $request->user(), null, $data['source_note'], null, ['rows' => count($rows)], 'catalogue_import', $id);

            return $id;
        }, 'csv');

        return to_route('owner.catalogue-import.index', ['import' => $id]);
    }

    public function post(Request $request, int $import, CatalogueService $service): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        PostingTransaction::run(function () use ($request, $import, $service): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $draft = DB::table('catalogue_imports')->where('id', $import)->lockForUpdate()->first();
            abort_if($draft === null, 404);
            if ($draft->posted_at !== null) {
                return;
            }
            $rows = json_decode($draft->rows, true, 512, JSON_THROW_ON_ERROR);
            $this->validateRows($rows);
            foreach ($rows as $row) {
                $key = CatalogueValues::nameKey($row['category']);
                $category = Category::query()->firstOrCreate(['name_key' => $key], ['name' => $row['category']]);
                $service->save($request->user(), ['sku' => $row['sku'], 'name' => $row['name'], 'category_id' => $category->id, 'unit_label' => $row['unit'], 'quantity_precision' => (int) $row['precision'], 'selling_price' => $row['selling_price'], 'purchase_cost' => $row['purchase_cost'] === '' ? null : $row['purchase_cost'], 'reorder_threshold' => $row['reorder_threshold'], 'barcode' => $row['barcode'] === '' ? null : $row['barcode'], 'is_active' => true, 'confirm_duplicate' => true]);
            }
            DB::table('catalogue_imports')->where('id', $import)->update(['posted_at' => now()]);
            AccessAudit::record('catalogue.import_posted', $request->user(), null, $draft->source_note, null, ['rows' => count($rows)], 'catalogue_import', $import);
        }, 'csv');

        return to_route('owner.catalogue-import.index', ['import' => $import])->with('status', 'Catalogue imported with zero stock. Enter reviewed opening counts separately.');
    }

    /** @param list<array<string, mixed>> $rows */
    private function validateRows(array $rows): void
    {
        Validator::make(['rows' => $rows], ['rows' => ['required', 'array', 'min:1', 'max:500'], 'rows.*.sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9._\/-]*$/', 'distinct', Rule::unique('products', 'sku')], 'rows.*.name' => ['required', 'string', 'max:255'], 'rows.*.category' => ['required', 'string', 'max:255'], 'rows.*.unit' => ['required', 'string', 'max:30'], 'rows.*.precision' => ['required', 'integer', 'between:0,3'], 'rows.*.selling_price' => ['required', 'regex:'.CatalogueValues::MONEY_RULE], 'rows.*.purchase_cost' => ['nullable', 'regex:'.CatalogueValues::MONEY_RULE], 'rows.*.barcode' => ['nullable', 'string', 'max:100', 'distinct', Rule::unique('products', 'barcode')], 'rows.*.reorder_threshold' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/']])->validate();
        foreach ($rows as $index => $row) {
            StockArithmetic::quantity($row['reorder_threshold'], (int) $row['precision'], 'rows.'.$index.'.reorder_threshold');
        }
    }
}
