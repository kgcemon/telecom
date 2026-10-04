<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Service;
use App\Services\Security\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-service configuration: the amount window, number length, whether a PIN
 * is required, and the auto-approval ceiling.
 *
 * `auto_limit` is the important one and was previously unlabelled: requests at
 * or below it are dispatched without review, and anything above waits for an
 * operator.
 */
class ServiceController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('admin.services.index', [
            'services' => Service::query()->orderBy('sorder')->orderBy('title')->get(),
            'symbol'   => $this->settings->currencySymbol(),
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:120'],
            'min_amount'    => ['required', 'numeric', 'min:0'],
            'max_amount'    => ['required', 'numeric', 'min:1', 'gte:min_amount'],
            'min_length'    => ['required', 'integer', 'min:1', 'max:30'],
            'max_length'    => ['required', 'integer', 'min:1', 'max:40', 'gte:min_length'],
            'auto_limit'    => ['required', 'numeric', 'min:0'],
            'sorder'        => ['required', 'integer', 'min:0', 'max:99'],
            'requr_pin'     => ['nullable', 'boolean'],
            'nid'           => ['nullable', 'boolean'],
            'name'          => ['nullable', 'boolean'],
            'sender_number' => ['nullable', 'boolean'],
            'status'        => ['nullable', 'boolean'],
        ], [], [
            'max_amount' => 'maximum amount',
            'max_length' => 'maximum number length',
            'auto_limit' => 'auto-approval limit',
        ]);

        foreach (['requr_pin', 'nid', 'name', 'sender_number', 'status'] as $flag) {
            $validated[$flag] = ! empty($validated[$flag]) ? 1 : 0;
        }

        $service->forceFill($validated)->save();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'service.updated',
            sprintf('Updated service %s', $service->title),
            ['service' => (int) $service->serviceid],
        );

        return back()->with('status', $service->title.' updated.');
    }
}
