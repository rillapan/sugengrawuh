<?php

namespace Tests\Unit;

use App\Models\HourlyPrice;
use App\Models\Playstation;
use Tests\TestCase;

class DurationPrecisionAndPricingTest extends TestCase
{
    /**
     * Test HH:MM:SS conversion to total seconds.
     */
    public function test_to_total_seconds()
    {
        $this->assertEquals(0, HourlyPrice::toTotalSeconds(0, 0, 0));
        $this->assertEquals(30, HourlyPrice::toTotalSeconds(0, 0, 30));
        $this->assertEquals(300, HourlyPrice::toTotalSeconds(0, 5, 0));
        $this->assertEquals(1800, HourlyPrice::toTotalSeconds(0, 30, 0));
        $this->assertEquals(3600, HourlyPrice::toTotalSeconds(1, 0, 0));
        $this->assertEquals(5400, HourlyPrice::toTotalSeconds(1, 30, 0));
        $this->assertEquals(4530, HourlyPrice::toTotalSeconds(1, 15, 30));
        $this->assertEquals(86400, HourlyPrice::toTotalSeconds(24, 0, 0));
    }

    /**
     * Test seconds breakdown to hours, minutes, seconds parts.
     */
    public function test_seconds_to_parts()
    {
        $parts = HourlyPrice::secondsToParts(4530);
        $this->assertEquals(['hours' => 1, 'minutes' => 15, 'seconds' => 30], $parts);

        $partsZero = HourlyPrice::secondsToParts(0);
        $this->assertEquals(['hours' => 0, 'minutes' => 0, 'seconds' => 0], $partsZero);

        $partsHalfHour = HourlyPrice::secondsToParts(1800);
        $this->assertEquals(['hours' => 0, 'minutes' => 30, 'seconds' => 0], $partsHalfHour);
    }

    /**
     * Test formatting seconds to HH:MM:SS.
     */
    public function test_format_seconds()
    {
        $this->assertEquals('00:00:00', HourlyPrice::formatSeconds(0));
        $this->assertEquals('00:00:30', HourlyPrice::formatSeconds(30));
        $this->assertEquals('00:05:00', HourlyPrice::formatSeconds(300));
        $this->assertEquals('00:30:00', HourlyPrice::formatSeconds(1800));
        $this->assertEquals('01:00:00', HourlyPrice::formatSeconds(3600));
        $this->assertEquals('01:15:30', HourlyPrice::formatSeconds(4530));
    }

    /**
     * Test Indonesian human duration strings.
     */
    public function test_humanize_seconds()
    {
        $this->assertEquals('30 detik', HourlyPrice::humanizeSeconds(30));
        $this->assertEquals('5 menit', HourlyPrice::humanizeSeconds(300));
        $this->assertEquals('30 menit', HourlyPrice::humanizeSeconds(1800));
        $this->assertEquals('1 jam', HourlyPrice::humanizeSeconds(3600));
        $this->assertEquals('1 jam 15 menit 30 detik', HourlyPrice::humanizeSeconds(4530));
    }

    /**
     * Test SR-BILLING-DURATION-001 pricing formula on base hourly rate Rp 30.000:
     * - 5 minutes -> Rp 2.500
     * - 30 minutes -> Rp 15.000
     * - 1h 15m 30s (4530s) -> raw Rp 37.750 -> Rp 38.000
     * - 30 seconds -> raw Rp 250 -> Rp 500
     */
    public function test_custom_pricing_formula_expected_results()
    {
        $ps = new Playstation();
        $ps->harga = 30000;

        // 1. 00:05:00 (300s) -> 30000 * 300 / 3600 = 2500 -> Rp 2.500
        $this->assertEquals(2500, $ps->calculateCustomPrice(300));

        // 2. 00:30:00 (1800s) -> 30000 * 1800 / 3600 = 15000 -> Rp 15.000
        $this->assertEquals(15000, $ps->calculateCustomPrice(1800));

        // 3. 01:15:30 (4530s) -> 30000 * 4530 / 3600 = 37750 -> Rp 38.000
        $this->assertEquals(38000, $ps->calculateCustomPrice(4530));

        // 4. 00:00:30 (30s) -> 30000 * 30 / 3600 = 250 -> Rp 500
        $this->assertEquals(500, $ps->calculateCustomPrice(30));
    }

    /**
     * Test ceiling rounding up to nearest Rp 500 boundary conditions:
     * - Rp 15.000 -> Rp 15.000
     * - Rp 15.001 -> Rp 15.500
     * - Rp 15.500 -> Rp 15.500
     * - Rp 15.501 -> Rp 16.000
     */
    public function test_rounding_up_boundary_conditions()
    {
        $roundUp = function (int $hargaPerJam, int $totalDetik) {
            $numerator = $hargaPerJam * $totalDetik;
            $denominator = 3600 * 500;
            return (int) (intdiv($numerator + $denominator - 1, $denominator) * 500);
        };

        // If raw price is exactly 15.000:
        // 36000 * 1500 = 54.000.000 / 3600 = 15.000
        $this->assertEquals(15000, $roundUp(36000, 1500));

        // Direct mathematical check with simulated integer formula
        // Let numerator = rawPrice * 3600
        $roundRaw = function (int $rawNumerator) {
            $denominator = 3600 * 500;
            return (int) (intdiv($rawNumerator + $denominator - 1, $denominator) * 500);
        };

        // Raw 15.000 => numerator = 15000 * 3600 = 54.000.000
        $this->assertEquals(15000, $roundRaw(15000 * 3600));

        // Raw 15.001 => numerator = 15001 * 3600
        $this->assertEquals(15500, $roundRaw(15001 * 3600));

        // Raw 15.250 => numerator = 15250 * 3600
        $this->assertEquals(15500, $roundRaw(15250 * 3600));

        // Raw 15.500 => numerator = 15500 * 3600
        $this->assertEquals(15500, $roundRaw(15500 * 3600));

        // Raw 15.501 => numerator = 15501 * 3600
        $this->assertEquals(16000, $roundRaw(15501 * 3600));

        // Raw 15.750 => numerator = 15750 * 3600
        $this->assertEquals(16000, $roundRaw(15750 * 3600));
    }
}

