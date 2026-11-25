<?php

namespace App\Payroll;

use App\Models\PayrollPeriod;
use App\Models\PayrollStatutoryRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StatutoryPayrollEngine
{
    public const CODES = ['PAYE', 'NSSF', 'PSSSF', 'NHIF', 'SDL', 'WCF', 'HESLB'];

    public function createRule(array $data, int $createdBy): PayrollStatutoryRule
    {
        return DB::transaction(function () use ($data, $createdBy): PayrollStatutoryRule {
            $rules = PayrollStatutoryRule::query()
                ->where('code', $data['code'])
                ->where('jurisdiction', $data['jurisdiction'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();
            $effectiveFrom = CarbonImmutable::parse($data['effective_from']);
            $effectiveTo = isset($data['effective_to']) ? CarbonImmutable::parse($data['effective_to']) : null;

            foreach ($rules as $rule) {
                if ($rule->effective_to === null && $rule->effective_from->lt($effectiveFrom)) {
                    $rule->update(['effective_to' => $effectiveFrom->subDay()->toDateString()]);
                }

                $ruleEnd = $rule->effective_to;
                if (($effectiveTo === null || $rule->effective_from->lte($effectiveTo))
                    && ($ruleEnd === null || $ruleEnd->gte($effectiveFrom))) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'This effective date overlaps an active rule version. Close the prior version or choose a non-overlapping date.',
                    ]);
                }
            }

            $version = ((int) PayrollStatutoryRule::query()
                ->where('code', $data['code'])
                ->where('jurisdiction', $data['jurisdiction'])
                ->max('version')) + 1;

            return PayrollStatutoryRule::create([
                ...$data,
                'created_by' => $createdBy,
                'version' => $version,
                'is_active' => true,
            ]);
        });
    }

    public function ruleForPeriod(string $code, string $jurisdiction, PayrollPeriod $period): PayrollStatutoryRule
    {
        $rules = PayrollStatutoryRule::query()
            ->where('code', $code)
            ->where('jurisdiction', $jurisdiction)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $period->starts_on)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->ends_on))
            ->orderByDesc('version')
            ->get();

        if ($rules->count() !== 1) {
            throw ValidationException::withMessages([
                'period' => $rules->isEmpty()
                    ? 'No active '.$code.' rule covers the full payroll period. Configure a verified statutory rule before processing.'
                    : 'More than one active '.$code.' rule covers the payroll period. Resolve the overlapping rule versions before processing.',
            ]);
        }

        return $rules->first();
    }

    public function contributionBase(PayrollStatutoryRule $rule, string $basic, string $gross, string $taxable, ?string $pensionable = null): string
    {
        $base = match ($rule->calculation_basis) {
            'basic' => $basic,
            'gross' => $gross,
            'taxable_pay' => $taxable,
            'pensionable_pay' => $pensionable ?? $basic,
            default => throw ValidationException::withMessages([
                'period' => 'The configured '.$rule->code.' rule has no supported calculation basis.',
            ]),
        };
        $base = bccomp($base, (string) ($rule->threshold ?? '0.00'), 2) > 0
            ? bcsub($base, (string) ($rule->threshold ?? '0.00'), 2)
            : '0.00';

        if ($rule->ceiling !== null && bccomp($base, $rule->ceiling, 2) > 0) {
            $base = $rule->ceiling;
        }

        return $base;
    }

    public function contribution(PayrollStatutoryRule $rule, string $base, string $side, PayrollCalculator $calculator): string
    {
        $rate = $side === 'employee' ? $rule->employee_rate : $rule->employer_rate;
        if ($rate === null) {
            throw ValidationException::withMessages([
                'period' => 'The configured '.$rule->code.' rule is missing its '.$side.' rate.',
            ]);
        }

        return $calculator->percent($base, $rate);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(PayrollStatutoryRule $rule): array
    {
        return [
            'id' => $rule->id,
            'code' => $rule->code,
            'jurisdiction' => $rule->jurisdiction,
            'version' => $rule->version,
            'effective_from' => $rule->effective_from->toDateString(),
            'effective_to' => $rule->effective_to?->toDateString(),
            'employee_rate' => $rule->employee_rate,
            'employer_rate' => $rule->employer_rate,
            'calculation_basis' => $rule->calculation_basis,
            'threshold' => $rule->threshold,
            'ceiling' => $rule->ceiling,
            'parameters' => $rule->parameters,
            'authority_reference' => $rule->authority_reference,
        ];
    }
}
