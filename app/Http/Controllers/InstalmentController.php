<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstalmentRequest;
use App\Models\Customer;
use App\Models\ShopSettings;
use App\Services\InstalmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InstalmentController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('payments/collect', ['customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'contact']), 'submission_key' => (string) Str::uuid(), 'mpesaNumber' => ShopSettings::query()->findOrFail(1)->mpesa_number]);
    }

    public function store(InstalmentRequest $request, InstalmentService $service): RedirectResponse
    {
        return to_route('payments.show', $service->collect($request->user(), $request->validated()));
    }
}
