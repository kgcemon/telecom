<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ResellerLevel;
use App\Models\LevelDefinition;
use App\Models\RechargeRequest;
use App\Models\Reseller;
use App\Models\Tariff;
use App\Services\Reseller\ResellerService;
use App\Services\Security\AuditLogger;
use App\Services\Security\PermissionService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Reseller administration.
 *
 * Every action is checked against PermissionService::canManage(), which
 * requires the actor to both outrank the target and have it inside their own
 * subtree. The legacy panel checked neither, so a sub-admin could open
 * `reseller_edit/213730` for an account belonging to a different sub-admin and
 * change its tariff, balance or password.
 */
class ResellerController
{
    public function __construct(
        private readonly ResellerService $resellers,
        private readonly PermissionService $permissions,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        $query = Reseller::query()
            ->with('parent:id,username,name')
            // An admin sees everything; anyone else sees only their own subtree.
            ->when(! $actor->level()?->isAdministrative() || $actor->level() !== ResellerLevel::Admin,
                fn ($q) => $q->where(fn ($w) => $w->downlineOf((int) $actor->id)->orWhere('id', $actor->id)))
            ->when($request->filled('level'), fn ($q) => $q->where('custype', $request->string('level')->toString()))
            ->when($request->filled('q'), fn ($q) => $q->search($request->string('q')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->integer('status')))
            ->where('delete', '!=', 1);

        $totals = (clone $query)->getQuery()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS main, COALESCE(SUM(bank_balance), 0) AS bank')
            ->first();

        return view('admin.resellers.index', [
            'resellers' => $query->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'levels'  => $this->levelLabels(),
            'filters' => $request->only(['level', 'q', 'status']),
            'symbol'  => $this->settings->currencySymbol(),
            'totals'  => [
                'count' => (int) ($totals->n ?? 0),
                'main'  => (float) ($totals->main ?? 0),
                'bank'  => (float) ($totals->bank ?? 0),
            ],
        ]);
    }

    public function show(Request $request, Reseller $reseller): View
    {
        $this->authoriseManage($request, $reseller);

        $monthStart = now()->startOfMonth()->format('Y-m-d');

        $activity = RechargeRequest::query()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS volume, COALESCE(SUM(cost), 0) AS charged')
            ->where('userid', $reseller->id)
            ->where('idate', '>=', $monthStart)
            ->first();

        return view('admin.resellers.show', [
            'reseller' => $reseller->load('parent:id,username,name', 'tariff:id,name'),
            'children' => Reseller::query()
                ->where('p_id', $reseller->id)
                ->where('delete', '!=', 1)
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
            'recent' => RechargeRequest::query()
                ->where('userid', $reseller->id)
                ->orderByDesc('id')
                ->limit(15)
                ->get(),
            'activity' => [
                'count'   => (int) ($activity->n ?? 0),
                'volume'  => (float) ($activity->volume ?? 0),
                'charged' => (float) ($activity->charged ?? 0),
            ],
            'permissions' => $this->permissions->flagsFor($reseller),
            'groups'      => $this->permissions->grouped(),
            'symbol'      => $this->settings->currencySymbol(),
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $this->actor($request);

        return view('admin.resellers.create', [
            'levels'  => $this->creatableLevels($actor),
            'tariffs' => Tariff::query()->orderBy('name')->get(['id', 'name']),
            'symbol'  => $this->settings->currencySymbol(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'username' => [
                'required', 'string', 'min:3', 'max:60',
                // Letters, digits, dot, dash, underscore only, so a username
                // cannot be confused with a phone number or carry markup.
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('reseller', 'username'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'pincode'  => ['required', 'string', 'min:4', 'max:12'],
            'custype'  => ['required', 'string', Rule::in(array_keys($this->creatableLevels($actor)))],
            'name'     => ['required', 'string', 'max:120'],
            'mobile'   => ['required', 'string', 'max:20'],
            'email'    => ['nullable', 'email', 'max:120'],
            'tarif'    => ['nullable', 'integer', 'exists:tarif,id'],
            'balance_limit' => ['nullable', 'numeric', 'min:0'],
            'note'     => ['nullable', 'string', 'max:200'],
            'webAccess'  => ['nullable', 'boolean'],
            'appsAccess' => ['nullable', 'boolean'],
        ], [], [
            'custype' => 'level',
            'tarif'   => 'tariff',
        ]);

        $reseller = $this->resellers->create($actor, $validated);

        $this->audit->record(
            $actor,
            'reseller.created',
            sprintf('Created %s (%s) at level %s', $reseller->username, $reseller->id, $reseller->custype),
            ['reseller_id' => $reseller->id],
        );

        return redirect()
            ->route('admin.resellers.show', $reseller)
            ->with('status', 'Reseller created. Share the credentials securely — they are not shown again.');
    }

    public function edit(Request $request, Reseller $reseller): View
    {
        $this->authoriseManage($request, $reseller);

        return view('admin.resellers.edit', [
            'reseller' => $reseller,
            'levels'   => $this->creatableLevels($this->actor($request)),
            'tariffs'  => Tariff::query()->orderBy('name')->get(['id', 'name']),
            'symbol'   => $this->settings->currencySymbol(),
        ]);
    }

    public function update(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authoriseManage($request, $reseller);

        $validated = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email'  => ['nullable', 'email', 'max:120'],
            'note'   => ['nullable', 'string', 'max:200'],
            'tarif'  => ['nullable', 'integer', 'exists:tarif,id'],
            'balance_limit' => ['nullable', 'numeric', 'min:0'],
            'status'      => ['nullable', 'boolean'],
            'login_allow' => ['nullable', 'boolean'],
            'webAccess'   => ['nullable', 'boolean'],
            'appsAccess'  => ['nullable', 'boolean'],
            'recharge'    => ['nullable', 'boolean'],
            'device_lock' => ['nullable', 'boolean'],
            'api'         => ['nullable', 'boolean'],
            // Optional reset; left blank the existing credential is untouched.
            'password' => ['nullable', 'string', 'min:8', 'max:100', 'confirmed'],
            'pincode'  => ['nullable', 'string', 'min:4', 'max:12'],
        ]);

        $changed = $this->resellers->update($reseller, $validated);

        $this->audit->record(
            $this->actor($request),
            'reseller.updated',
            sprintf('Updated %s (%s)', $reseller->username, $reseller->id),
            ['reseller_id' => $reseller->id, 'fields' => $changed],
        );

        return back()->with('status', 'Reseller updated.');
    }

    public function syncPermissions(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authoriseManage($request, $reseller);

        $validated = $request->validate([
            'permissions'   => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionService::PERMISSIONS))],
        ]);

        $actor = $this->actor($request);
        $granted = $validated['permissions'] ?? [];

        // An actor cannot grant a permission they do not themselves hold —
        // otherwise a sub-admin could promote an account above its own reach.
        if ($actor->level() !== ResellerLevel::Admin) {
            $granted = array_values(array_filter(
                $granted,
                fn (string $permission) => $this->permissions->allows($actor, $permission),
            ));
        }

        $this->permissions->sync($reseller, $granted);

        $this->audit->record(
            $actor,
            'reseller.permissions',
            sprintf('Updated permissions for %s', $reseller->username),
            ['reseller_id' => $reseller->id, 'granted' => $granted],
        );

        return back()->with('status', 'Permissions updated.');
    }

    /**
     * Soft delete. The row is flagged rather than removed because transaction
     * and ledger history reference it by id; a hard delete would orphan the
     * financial record.
     */
    public function destroy(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authoriseManage($request, $reseller);

        if ($reseller->children()->where('delete', '!=', 1)->exists()) {
            return back()->with('error', 'This account still has resellers under it. Move or remove them first.');
        }

        if ((float) $reseller->balance > 0 || (float) $reseller->bank_balance > 0) {
            return back()->with('error', 'This account still holds a balance. Return it before deleting.');
        }

        $this->resellers->softDelete($reseller);

        $this->audit->record(
            $this->actor($request),
            'reseller.deleted',
            sprintf('Deleted %s (%s)', $reseller->username, $reseller->id),
            ['reseller_id' => $reseller->id],
        );

        return redirect()
            ->route('admin.resellers.index')
            ->with('status', 'Reseller deleted. Their transaction history is retained.');
    }

    // ------------------------------------------------------------ internals

    private function actor(Request $request): Reseller
    {
        $actor = $request->attributes->get('current_reseller');

        abort_unless($actor instanceof Reseller, 403);

        return $actor;
    }

    private function authoriseManage(Request $request, Reseller $target): void
    {
        abort_unless(
            $this->permissions->canManage($this->actor($request), $target),
            403,
            'This account is not in your network.',
        );
    }

    /** Levels the actor may create, labelled with the operator's own wording. */
    private function creatableLevels(Reseller $actor): array
    {
        $labels = $this->levelLabels();
        $allowed = $actor->level()?->creatableLevels() ?? [];

        $out = [];

        foreach ($allowed as $level) {
            $out[$level->value] = $labels[$level->value] ?? $level->defaultLabel();
        }

        return $out;
    }

    private function levelLabels(): array
    {
        $custom = LevelDefinition::query()->pluck('real_name', 'name');

        $out = [];

        foreach (ResellerLevel::cases() as $level) {
            $out[$level->value] = filled($custom[$level->value] ?? null)
                ? (string) $custom[$level->value]
                : $level->defaultLabel();
        }

        return $out;
    }
}
