<?php

namespace App\Payroll;

use App\Models\PayrollOvertimeRule;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OvertimePayrollEngine
{
    public const TYPES = ['normal', 'weekend', 'holiday'];

    public function createRule(array $data, int $createdBy): PayrollOvertimeRule
    {
        return DB::transaction(function () use ($data, $createdBy): PayrollOvertimeRule {
            $rules = PayrollOvertimeRule::query()
                ->where('country_code', $data['country_code'])
                ->where('currency', $data['currency'])
                ->where('frequency', $data['frequency'])
                ->where('overtime_type', $data['overtime_type'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();
            $effectiveFrom = CarbonImmutable::parse($data['effective_from']);
            $effectiveTo = isset($data['effective_to']) ? CarbonImmutable::parse($data['effective_to']) : null;

            foreach ($rules as $rule) {
                if ($rule->effective_to === null && $rule->effective_from->lt($effectiveFrom)) {
                    $rule->update(['effective_to' => $effectiveFrom->subDay()->toDateString()]);
                }

                if (($effectiveTo === null || $rule->effective_from->lte($effectiveTo))
                    && ($rule->effective_to === null || $rule->effective_to->gte($effectiveFrom))) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'The overtime rule dates overlap an active version for this pay frequency and type.',
                    ]);
                }
            }

            $version = ((int) PayrollOvertimeRule::query()
                ->where('country_code', $data['country_code'])
                ->where('currency', $data['currency'])
                ->where('frequency', $data['frequency'])
                ->where('overtime_type', $data['overtime_type'])
                ->max('version')) + 1;

            return PayrollOvertimeRule::create([
                ...$data,
                'created_by' => $createdBy,
                'version' => $version,
                'is_active' => true,
            ]);
        });
    }

    public function ruleForDate(PayrollPeriod $period, string $type, string $date): PayrollOvertimeRule
    {
        $rules = PayrollOvertimeRule::query()
            ->where('country_code', $period->country_code)
            ->where('currency', $period->currency)
            ->where('frequency', $period->frequency)
            ->where('overtime_type', $type)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('version')
            ->get();

        if ($rules->count() !== 1) {
            throw ValidationException::withMessages([
                'period' => $rules->isEmpty()
                    ? 'No active '.$type.' overtime rule covers '.$date.'. Configure a verified employer rule before processing.'
                    : 'Multiple active '.$type.' overtime rules cover '.$date.'. Resolve the overlap before processing.',
            ]);
        }

        return $rules->first();
    }

    public function amount(PayrollOvertimeRule $rule, string $basic, string $gross, string $taxablePay, string $hours, PayrollCalculator $calculator): string
    {
        $base = match ($rule->calculation_basis) {
            'basic' => $basic,
            'gross' => $gross,
            'taxable_pay' => $taxablePay,
            default => throw ValidationException::withMessages([
                'period' => 'The configured overtime rule has an unsupported calculation basis.',
            ]),
        };
        if (bccomp($rule->hours_per_period, '0', 2) <= 0 || bccomp($hours, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'period' => 'Overtime hours and the configured hours-per-period divisor must both be greater than zero.',
            ]);
        }

        $hourlyRate = bcdiv($base, $rule->hours_per_period, 8);
        $weightedHours = bcmul($hours, $rule->multiplier, 8);

        return $calculator->money(bcmul($hourlyRate, $weightedHours, 8));
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(PayrollOvertimeRule $rule): array
    {
        return [
            'id' => $rule->id,
            'country_code' => $rule->country_code,
            'currency' => $rule->currency,
            'frequency' => $rule->frequency,
            'overtime_type' => $rule->overtime_type,
            'version' => $rule->version,
            'calculation_basis' => $rule->calculation_basis,
            'hours_per_period' => $rule->hours_per_period,
            'multiplier' => $rule->multiplier,
            'is_taxable' => $rule->is_taxable,
            'is_pensionable' => $rule->is_pensionable,
            'effective_from' => $rule->effective_from->toDateString(),
            'effective_to' => $rule->effective_to?->toDateString(),
            'authority_reference' => $rule->authority_reference,
        ];
    }
}
