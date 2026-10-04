<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\CountryOperator;
use App\Models\Operator;
use App\Services\Recharge\OperatorResolver;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Operators and their number prefixes.
 *
 * Adding a prefix here is all that is needed for the recharge engine to
 * recognise it, because OperatorResolver reads this table instead of the
 * hard-coded prefix ladders the legacy code used in four separate places.
 */
class OperatorController
{
    public function __construct(
        private readonly OperatorResolver $operators,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('admin.operators.index', [
            'operators' => Operator::query()->orderBy('title_op')->get(),
            'prefixes'  => CountryOperator::query()->orderBy('oprefix')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'opname'   => ['required', 'string', 'max:60'],
            'title_op' => ['required', 'string', 'max:120'],
            'pcode'    => ['required', 'string', 'max:11'],
            'ex'       => ['required', 'string', 'max:10'],
            'minimum'  => ['nullable', 'numeric', 'min:0'],
            'maximum'  => ['nullable', 'numeric', 'min:0'],
            'status'   => ['nullable', 'boolean'],
        ], [], [
            'pcode' => 'number prefix',
            'ex'    => 'short code',
        ]);

        $validated['status'] = ! empty($validated['status']) ? 1 : 0;

        $operator = Operator::create($validated);

        $this->operators->flushCache();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'operator.created',
            sprintf('Added operator %s (%s)', $operator->title_op, $operator->ex),
        );

        return back()->with('status', 'Operator added.');
    }

    public function update(Request $request, Operator $operator): RedirectResponse
    {
        $validated = $request->validate([
            'title_op' => ['required', 'string', 'max:120'],
            'pcode'    => ['required', 'string', 'max:11'],
            'ex'       => ['required', 'string', 'max:10'],
            'minimum'  => ['nullable', 'numeric', 'min:0'],
            'maximum'  => ['nullable', 'numeric', 'min:0'],
            'status'   => ['nullable', 'boolean'],
        ]);

        $validated['status'] = ! empty($validated['status']) ? 1 : 0;

        $operator->forceFill($validated)->save();

        $this->operators->flushCache();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'operator.updated',
            sprintf('Updated operator %s', $operator->title_op),
        );

        return back()->with('status', 'Operator updated.');
    }
}
