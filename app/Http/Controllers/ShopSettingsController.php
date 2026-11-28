<?php

namespace App\Http\Controllers;

use App\Models\ShopSettings;
use App\Services\AccessAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ShopSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('owner/shop-settings', ['settings' => ShopSettings::query()->findOrFail(1), 'status' => $request->session()->get('status')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:500'],
            'contact' => ['nullable', 'string', 'max:100'],
            'tax_registration' => ['nullable', 'string', 'max:255'], 'tax_label' => ['nullable', 'string', 'max:50'], 'future_tax_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'mpesa_number' => ['nullable', 'regex:/^(?:0[17]\d{8}|\+?254[17]\d{8})$/', 'confirmed'],
            'version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($data, $request): void {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            if ($settings->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Settings changed in another session. Reload before saving.']);
            }
            $before = $settings->only(['shop_name', 'address', 'contact', 'mpesa_number', 'tax_registration', 'tax_label', 'future_tax_rate', 'version']);
            $settings->fill(['shop_name' => $data['shop_name'], 'address' => $data['address'] ?? null, 'contact' => $data['contact'] ?? null, 'mpesa_number' => $data['mpesa_number'] ?? null]);
            $settings->version++;
            $settings->fill(array_intersect_key($data, array_flip(['tax_registration', 'tax_label', 'future_tax_rate'])));
            $settings->save();
            AccessAudit::record('shop.settings_changed', $request->user(), null, $data['reason'], $before, $settings->only(['shop_name', 'address', 'contact', 'mpesa_number', 'tax_registration', 'tax_label', 'future_tax_rate', 'version']), 'shop_settings', 1);
        });

        return back()->with('status', 'Shop settings saved. Receiving-number changes apply to future manual payments only.');
    }
}
