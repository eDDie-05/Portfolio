<?php

namespace App\Payroll;

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

    public function tanzaniaMainlandPaye(string $taxablePay, string $frequency, string $taxResidency): string
    {
        $periodsPerYear = match ($frequency) {
            'monthly' => 12,
            'biweekly' => 26,
            'weekly' => 52,
            default => throw new InvalidArgumentException('Unsupported payroll frequency for PAYE.'),
        };
        $annualTaxablePay = bcmul($taxablePay, (string) $periodsPerYear, 4);

        if ($taxResidency === 'non_resident') {
            return $this->money(bcdiv(bcmul($annualTaxablePay, '15', 8), (string) ($periodsPerYear * 100), 8));
        }

        $annualBands = [
            ['limit' => '3240000', 'rate' => '0'],
            ['limit' => '6240000', 'rate' => '8'],
            ['limit' => '9120000', 'rate' => '20'],
            ['limit' => '12000000', 'rate' => '25'],
            ['limit' => null, 'rate' => '30'],
        ];
        $remainingPay = $annualTaxablePay;
        $previousLimit = '0';
        $annualTax = '0';

        foreach ($annualBands as $band) {
            if ($band['limit'] === null) {
                $taxableInBand = $remainingPay;
            } else {
                $bandWidth = bcsub($band['limit'], $previousLimit, 4);
                $taxableInBand = bccomp($remainingPay, $bandWidth, 4) > 0 ? $bandWidth : $remainingPay;
            }

            if (bccomp($taxableInBand, '0', 4) <= 0) {
                break;
            }

            $annualTax = bcadd($annualTax, bcdiv(bcmul($taxableInBand, $band['rate'], 8), '100', 8), 8);
            $remainingPay = bcsub($remainingPay, $taxableInBand, 4);

            if ($band['limit'] !== null) {
                $previousLimit = $band['limit'];
            }
        }

        return $this->money(bcdiv($annualTax, (string) $periodsPerYear, 8));
    }
}
