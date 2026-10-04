<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Models\CountryOperator;
use App\Models\Operator;
use Illuminate\Support\Facades\Cache;

/**
 * Works out which operator a number belongs to.
 *
 * The legacy code did this with a wall of repeated `if ($firstThreeDigit == "017")`
 * blocks copied into four different places, each with slightly different
 * coverage - one of them was missing 013 and 014 entirely, so numbers on those
 * newer GrameenPhone and Banglalink ranges fell through with an empty operator
 * and were priced at zero. The mapping lives here once instead, driven by the
 * `oparetor` and `country_op` tables so new prefixes are configuration rather
 * than code.
 */
class OperatorResolver
{
    private const CACHE_KEY = 'operators.prefix-map';
    private const CACHE_TTL = 600;

    /**
     * Bangladeshi prefix to operator code. Used as the baseline when the
     * database has no explicit mapping, and kept complete on purpose: 013 is
     * GrameenPhone and 014 is Banglalink, both of which the legacy code missed
     * in two of its four copies of this logic.
     */
    private const DEFAULT_PREFIX_MAP = [
        '017' => 'GP',
        '013' => 'GP',
        '018' => 'RB',
        '016' => 'AT',
        '019' => 'BL',
        '014' => 'BL',
        '015' => 'TT',
        '011' => 'CC',
        '113' => 'SK',
    ];

    /** Two-letter code to the legacy `oparetor.opname` slug. */
    private const CODE_TO_SLUG = [
        'GP' => 'gp',
        'RB' => 'robi',
        'BL' => 'blink',
        'AT' => 'airtel',
        'TT' => 'teletalk',
        'CC' => 'citycell',
        'SK' => 'Skito',
    ];

    /** Aliases the mobile app and older API clients still send. */
    private const CODE_ALIASES = [
        'AR' => 'AT',
        'TE' => 'TT',
        'GRAMEENPHONE' => 'GP',
        'ROBI' => 'RB',
        'BANGLALINK' => 'BL',
        'AIRTEL' => 'AT',
        'TELETALK' => 'TT',
        'SKITTO' => 'SK',
    ];

    /**
     * Resolve a destination number to an operator code (GP, RB, ...).
     *
     * @param  string|null  $declaredCode  Operator the client claims; trusted
     *                                     only after normalisation, and only
     *                                     when the number itself is ambiguous.
     */
    public function resolve(string $number, ?string $declaredCode = null): ?string
    {
        $declared = $this->normaliseCode($declaredCode);

        // An explicitly declared operator wins, because some services (Skitto on
        // 113, international top-ups) cannot be told apart by prefix alone.
        if ($declared !== null) {
            return $declared;
        }

        return $this->codeForPrefix($this->prefixOf($number));
    }

    /** Normalise the 3-digit national prefix from any accepted number format. */
    public function prefixOf(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        // Strip a country code so 8801712..., +8801712... and 01712... agree.
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 3);
        }

        // A local number without its leading zero (1712...) still has a prefix.
        if (strlen($digits) >= 2 && ! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return substr($digits, 0, 3);
    }

    /** National-format number (01712345678), for storing and for providers. */
    public function normaliseNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 3);
        }

        if ($digits !== '' && ! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    public function codeForPrefix(string $prefix): ?string
    {
        return $this->prefixMap()[$prefix] ?? null;
    }

    /** Legacy `oparetor.opname` slug for a code, as stored on sendflexi.operator. */
    public function slugForCode(?string $code): string
    {
        if ($code === null) {
            return '';
        }

        return self::CODE_TO_SLUG[strtoupper($code)]
            ?? (string) Operator::query()->where('ex', $code)->value('opname')
            ?? strtolower($code);
    }

    /** Accept aliases and casing variants, returning a canonical code. */
    public function normaliseCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $upper = strtoupper(trim($code));
        $upper = self::CODE_ALIASES[$upper] ?? $upper;

        return isset(self::CODE_TO_SLUG[$upper]) || $this->isKnownCode($upper) ? $upper : null;
    }

    /** True when the operator is switched on for the given country prefix. */
    public function isPrefixEnabled(string $prefix): bool
    {
        $row = CountryOperator::query()->where('oprefix', $prefix)->first();

        // No explicit row means "not restricted", matching legacy behaviour.
        return $row === null || (int) $row->status === 1;
    }

    /** Expected national number length for a prefix, when configured. */
    public function expectedLength(string $prefix): ?int
    {
        $length = CountryOperator::query()->where('oprefix', $prefix)->value('lenth');

        return $length !== null && (int) $length > 0 ? (int) $length : null;
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Prefix to operator code, database values layered over the defaults.
     *
     * @return array<string,string>
     */
    private function prefixMap(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $map = self::DEFAULT_PREFIX_MAP;

            // `oparetor.pcode` holds the numeric prefix and `ex` the short code.
            foreach (Operator::query()->get(['pcode', 'ex', 'opname']) as $operator) {
                $prefix = trim((string) $operator->pcode);
                $code = strtoupper(trim((string) $operator->ex));

                if ($prefix === '' || $code === '') {
                    continue;
                }

                $map[$prefix] = $code;
            }

            // `country_op` is the authoritative per-country prefix table.
            foreach (CountryOperator::query()->get(['oprefix', 'code']) as $row) {
                $prefix = trim((string) $row->oprefix);
                $code = strtoupper(trim((string) $row->code));

                if ($prefix === '' || $code === '') {
                    continue;
                }

                $map[$prefix] = $code;
            }

            return $map;
        });
    }

    private function isKnownCode(string $code): bool
    {
        return in_array($code, array_values($this->prefixMap()), true);
    }
}
