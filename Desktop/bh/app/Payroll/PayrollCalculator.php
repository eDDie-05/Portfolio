<?php

namespace App\Payroll;

use Illuminate\Support\Arr;
use InvalidArgumentException;

class PayrollCalculator
{
    public function money(string|int|float $amount): string
    {
        $amount = trim((string) $amount);
        if (! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/', $amount)) {
            throw new InvalidArgumentException('Payroll amounts must be non-negative decimal values.');
        }

        $roundedCents = bcdiv(bcadd($amount, '0.005', 6), '0.01', 0);

        return bcdiv($roundedCents, '100', 2);
    }

    public function percent(string $baseAmount, string $ratePercent): string
    {
        if (! preg_match('/^\d{1,5}(?:\.\d{1,4})?$/', $ratePercent) || bccomp($ratePercent, '1000', 4) === 1) {
            throw new InvalidArgumentException('The configured percentage is outside the supported range.');
        }

        $unrounded = bcdiv(bcmul($baseAmount, $ratePercent, 8), '100', 8);

        return $this->money($unrounded);
    }

    /**
     * @param  array{bands: array<int, array{limit: string|int|float|null, rate: string|int|float}>, non_resident_rate: string|int|float}  $parameters
     */
    public function progressiveTax(string $taxablePay, string $frequency, array $parameters, string $taxResidency): string
    {
        if (! in_array($taxResidency, ['resident', 'non_resident'], true)) {
            throw new InvalidArgumentException('Tax residency must be configured before progressive tax can be calculated.');
        }

        $periodsPerYear = match ($frequency) {
            'monthly' => 12,
            'biweekly' => 26,
            'weekly' => 52,
            default => throw new InvalidArgumentException('Unsupported payroll frequency for progressive tax.'),
        };
        $annualTaxablePay = bcmul($taxablePay, (string) $periodsPerYear, 4);

        if ($taxResidency === 'non_resident') {
            $rate = (string) Arr::get($parameters, 'non_resident_rate', '');
            if ($rate === '') {
                throw new InvalidArgumentException('A non-resident tax rate is required by the configured rule.');
            }

            return $this->money(bcdiv(bcmul($annualTaxablePay, $rate, 8), (string) ($periodsPerYear * 100), 8));
        }

        $annualBands = $parameters['bands'] ?? [];
        if ($annualBands === []) {
            throw new InvalidArgumentException('Progressive tax bands are required by the configured rule.');
        }

        $remainingPay = $annualTaxablePay;
        $previousLimit = '0';
        $annualTax = '0';

        foreach ($annualBands as $index => $band) {
            $limit = $band['limit'] ?? null;
            $rate = (string) ($band['rate'] ?? '');
            if ($rate === '' || ! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/', $rate) || bccomp($rate, '100', 4) === 1) {
                throw new InvalidArgumentException('The configured progressive tax rate is invalid.');
            }

            if ($limit === null && $index !== array_key_last($annualBands)) {
                throw new InvalidArgumentException('Only the final progressive tax band may have no upper limit.');
            }
            if ($limit === null) {
                $taxableInBand = $remainingPay;
            } else {
                $limit = (string) $limit;
                if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $limit) || bccomp($limit, $previousLimit, 2) <= 0) {
                    throw new InvalidArgumentException('The configured progressive tax thresholds must be increasing.');
                }
                $bandWidth = bcsub($limit, $previousLimit, 4);
                $taxableInBand = bccomp($remainingPay, $bandWidth, 4) > 0 ? $bandWidth : $remainingPay;
            }

            if (bccomp($taxableInBand, '0', 4) <= 0) {
                break;
            }

            $annualTax = bcadd($annualTax, bcdiv(bcmul($taxableInBand, $rate, 8), '100', 8), 8);
            $remainingPay = bcsub($remainingPay, $taxableInBand, 4);

            if ($limit !== null) {
                $previousLimit = $limit;
            }
        }

        if (($annualBands[array_key_last($annualBands)]['limit'] ?? null) !== null) {
            throw new InvalidArgumentException('The final progressive tax band must have no upper limit.');
        }

        return $this->money(bcdiv($annualTax, (string) $periodsPerYear, 8));
    }
}
