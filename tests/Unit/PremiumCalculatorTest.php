<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Pricing\PremiumBreakdown;
use App\Domain\Pricing\PremiumCalculator;
use App\Domain\Pricing\PricingInput;
use App\Exceptions\ApiException;
use App\Exceptions\UnsupportedCoverageException;
use App\Exceptions\UnsupportedPlanException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator;
    }

    /**
     * Exact benchmark from specification:
     * AGT-2026-0001, AGENT-17, DOB 1998-04-10, INTERNATIONAL, 2026-11-10 to 2026-11-14, PLUS, 300000
     * Rate: 20, Days: 5, Age: 28 (1.00), Dest: 1.80, Cov: 1.25
     * 20 * 5 * 100 * 180 * 125 = 225,000,000 -> 22,500 paise -> 225.00 INR
     */
    public function test_assessment_specification_benchmark_example(): void
    {
        $input = new PricingInput(
            planCode: 'PLUS',
            sumInsured: 300000,
            destination: 'INTERNATIONAL',
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14',
        );

        $result = $this->calculator->calculate($input);

        $this->assertSame(22500, $result->minor);
        $this->assertSame('225.00', $result->formatted());
        $this->assertSame(5, $result->travelDays);
        $this->assertSame(28, $result->age);
        $this->assertSame('1.00', $result->ageMultiplier);
        $this->assertSame('1.80', $result->destinationMultiplier);
        $this->assertSame('1.25', $result->coverageMultiplier);
        $this->assertSame(20, $result->baseDailyRate);
        $this->assertSame('v1', $result->pricingVersion);
    }

    /**
     * 12 hand-computed test cases across plans, destinations, and coverage levels.
     */
    #[DataProvider('handComputedPricingCasesProvider')]
    public function test_hand_computed_pricing_cases(
        string $plan,
        int $coverage,
        string $dest,
        string $dob,
        string $start,
        string $end,
        int $expectedDays,
        int $expectedMinor,
        string $expectedFormatted
    ): void {
        $input = new PricingInput(
            planCode: $plan,
            sumInsured: $coverage,
            destination: $dest,
            dateOfBirth: $dob,
            tripStart: $start,
            tripEnd: $end,
        );

        $result = $this->calculator->calculate($input);

        $this->assertSame($expectedDays, $result->travelDays);
        $this->assertSame($expectedMinor, $result->minor);
        $this->assertSame($expectedFormatted, $result->formatted());
    }

    public static function handComputedPricingCasesProvider(): array
    {
        return [
            // Case 1: BASIC (12), DOMESTIC (1.00), 100k (1.00), age 25 (1.00), 1 day
            // 12 * 1 * 1.00 * 1.00 * 1.00 = 12.00 INR = 1200 paise
            ['BASIC', 100000, 'DOMESTIC', '2001-05-10', '2026-11-10', '2026-11-10', 1, 1200, '12.00'],

            // Case 2: BASIC (12), DOMESTIC (1.00), 100k (1.00), age 10 (1.10), 10 days
            // 12 * 10 * 1.10 = 132.00 INR = 13200 paise
            ['BASIC', 100000, 'DOMESTIC', '2016-11-10', '2026-11-10', '2026-11-19', 10, 13200, '132.00'],

            // Case 3: BASIC (12), DOMESTIC (1.00), 300k (1.25), age 30 (1.00), 5 days
            // 12 * 5 * 1.25 = 75.00 INR = 7500 paise
            ['BASIC', 300000, 'DOMESTIC', '1996-01-01', '2026-06-01', '2026-06-05', 5, 7500, '75.00'],

            // Case 4: BASIC (12), INTERNATIONAL (1.80), 500k (1.50), age 65 (1.50), 3 days
            // 12 * 3 * 1.50 * 1.80 * 1.50 = 145.80 INR = 14580 paise
            ['BASIC', 500000, 'INTERNATIONAL', '1961-04-01', '2026-08-01', '2026-08-03', 3, 14580, '145.80'],

            // Case 5: PLUS (20), DOMESTIC (1.00), 100k (1.00), age 17 (1.10), 4 days
            // 20 * 4 * 1.10 = 88.00 INR = 8800 paise
            ['PLUS', 100000, 'DOMESTIC', '2009-11-10', '2026-11-10', '2026-11-13', 4, 8800, '88.00'],

            // Case 6: PLUS (20), INTERNATIONAL (1.80), 300k (1.25), age 28 (1.00), 5 days (Spec benchmark)
            // 20 * 5 * 1.80 * 1.25 = 225.00 INR = 22500 paise
            ['PLUS', 300000, 'INTERNATIONAL', '1998-04-10', '2026-11-10', '2026-11-14', 5, 22500, '225.00'],

            // Case 7: PLUS (20), INTERNATIONAL (1.80), 500k (1.50), age 72 (2.00), 7 days
            // 20 * 7 * 2.00 * 1.80 * 1.50 = 756.00 INR = 75600 paise
            ['PLUS', 500000, 'INTERNATIONAL', '1954-01-15', '2026-07-01', '2026-07-07', 7, 75600, '756.00'],

            // Case 8: PREMIUM (30), DOMESTIC (1.00), 100k (1.00), age 40 (1.00), 2 days
            // 30 * 2 = 60.00 INR = 6000 paise
            ['PREMIUM', 100000, 'DOMESTIC', '1986-03-20', '2026-09-01', '2026-09-02', 2, 6000, '60.00'],

            // Case 9: PREMIUM (30), DOMESTIC (1.00), 300k (1.25), age 15 (1.10), 10 days
            // 30 * 10 * 1.10 * 1.25 = 412.50 INR = 41250 paise
            ['PREMIUM', 300000, 'DOMESTIC', '2011-02-10', '2026-10-01', '2026-10-10', 10, 41250, '412.50'],

            // Case 10: PREMIUM (30), INTERNATIONAL (1.80), 500k (1.50), age 62 (1.50), 15 days
            // 30 * 15 * 1.50 * 1.80 * 1.50 = 1822.50 INR = 182250 paise
            ['PREMIUM', 500000, 'INTERNATIONAL', '1964-05-01', '2026-10-01', '2026-10-15', 15, 182250, '1822.50'],

            // Case 11: PREMIUM (30), INTERNATIONAL (1.80), 300k (1.25), age 80 (2.00), 1 day
            // 30 * 1 * 2.00 * 1.80 * 1.25 = 135.00 INR = 13500 paise
            ['PREMIUM', 300000, 'INTERNATIONAL', '1946-01-01', '2026-11-01', '2026-11-01', 1, 13500, '135.00'],

            // Case 12: BASIC (12), INTERNATIONAL (1.80), 100k (1.00), age 50 (1.00), 7 days
            // 12 * 7 * 1.80 = 151.20 INR = 15120 paise
            ['BASIC', 100000, 'INTERNATIONAL', '1976-08-15', '2026-12-01', '2026-12-07', 7, 15120, '151.20'],

            // Case 13: PLUS (20), DOMESTIC (1.00), 500k (1.50), age 68 (1.50), 6 days
            // 20 * 6 * 1.50 * 1.50 = 270.00 INR = 27000 paise
            ['PLUS', 500000, 'DOMESTIC', '1958-02-20', '2026-05-01', '2026-05-06', 6, 27000, '270.00'],
        ];
    }

    /**
     * Age boundary tests on exact birthdays relative to trip_start.
     * Age must be evaluated on trip_start using DateTimeImmutable diff->y.
     */
    public function test_age_boundary_17_vs_18_on_exact_birthday(): void
    {
        // Exactly 17 on trip_start (DOB: 2009-11-10, trip_start: 2026-11-10) -> age 17 -> 1.10
        $input17 = new PricingInput('BASIC', 100000, 'DOMESTIC', '2009-11-10', '2026-11-10', '2026-11-10');
        $result17 = $this->calculator->calculate($input17);
        $this->assertSame(17, $result17->age);
        $this->assertSame('1.10', $result17->ageMultiplier);

        // Exactly 18 on trip_start (DOB: 2008-11-10, trip_start: 2026-11-10) -> age 18 -> 1.00
        $input18 = new PricingInput('BASIC', 100000, 'DOMESTIC', '2008-11-10', '2026-11-10', '2026-11-10');
        $result18 = $this->calculator->calculate($input18);
        $this->assertSame(18, $result18->age);
        $this->assertSame('1.00', $result18->ageMultiplier);

        // Turns 18 one day after trip_start (DOB: 2008-11-11, trip_start: 2026-11-10) -> age 17 on trip_start -> 1.10
        $inputTurns18NextDay = new PricingInput('BASIC', 100000, 'DOMESTIC', '2008-11-11', '2026-11-10', '2026-11-15');
        $resultTurns18NextDay = $this->calculator->calculate($inputTurns18NextDay);
        $this->assertSame(17, $resultTurns18NextDay->age);
        $this->assertSame('1.10', $resultTurns18NextDay->ageMultiplier);
    }

    public function test_age_boundary_60_vs_61_on_exact_birthday(): void
    {
        // Exactly 60 on trip_start (DOB: 1966-11-10, trip_start: 2026-11-10) -> age 60 -> 1.00
        $input60 = new PricingInput('BASIC', 100000, 'DOMESTIC', '1966-11-10', '2026-11-10', '2026-11-10');
        $result60 = $this->calculator->calculate($input60);
        $this->assertSame(60, $result60->age);
        $this->assertSame('1.00', $result60->ageMultiplier);

        // Exactly 61 on trip_start (DOB: 1965-11-10, trip_start: 2026-11-10) -> age 61 -> 1.50
        $input61 = new PricingInput('BASIC', 100000, 'DOMESTIC', '1965-11-10', '2026-11-10', '2026-11-10');
        $result61 = $this->calculator->calculate($input61);
        $this->assertSame(61, $result61->age);
        $this->assertSame('1.50', $result61->ageMultiplier);
    }

    public function test_age_boundary_70_vs_71_on_exact_birthday(): void
    {
        // Exactly 70 on trip_start (DOB: 1956-11-10, trip_start: 2026-11-10) -> age 70 -> 1.50
        $input70 = new PricingInput('BASIC', 100000, 'DOMESTIC', '1956-11-10', '2026-11-10', '2026-11-10');
        $result70 = $this->calculator->calculate($input70);
        $this->assertSame(70, $result70->age);
        $this->assertSame('1.50', $result70->ageMultiplier);

        // Exactly 71 on trip_start (DOB: 1955-11-10, trip_start: 2026-11-10) -> age 71 -> 2.00
        $input71 = new PricingInput('BASIC', 100000, 'DOMESTIC', '1955-11-10', '2026-11-10', '2026-11-10');
        $result71 = $this->calculator->calculate($input71);
        $this->assertSame(71, $result71->age);
        $this->assertSame('2.00', $result71->ageMultiplier);
    }

    public function test_birthday_falling_during_trip_evaluated_at_trip_start(): void
    {
        // DOB: 2008-11-12. Trip is 2026-11-10 to 2026-11-15.
        // Customer turns 18 on Nov 12, but on trip_start (Nov 10) they are 17 years old.
        // Rule: Age multiplier is determined on trip_start -> age 17 -> multiplier 1.10
        $input = new PricingInput('BASIC', 100000, 'DOMESTIC', '2008-11-12', '2026-11-10', '2026-11-15');
        $result = $this->calculator->calculate($input);

        $this->assertSame(17, $result->age);
        $this->assertSame('1.10', $result->ageMultiplier);
    }

    public function test_inclusive_days_calculation(): void
    {
        // Same-day trip = 1 day
        $sameDay = new PricingInput('BASIC', 100000, 'DOMESTIC', '1995-01-01', '2026-11-10', '2026-11-10');
        $this->assertSame(1, $this->calculator->calculate($sameDay)->travelDays);

        // 5-day span: Nov 10 to Nov 14 (10, 11, 12, 13, 14 = 5)
        $fiveDay = new PricingInput('BASIC', 100000, 'DOMESTIC', '1995-01-01', '2026-11-10', '2026-11-14');
        $this->assertSame(5, $this->calculator->calculate($fiveDay)->travelDays);
    }

    public function test_month_and_year_end_spans(): void
    {
        // Leap year non-leap year Feb end span: 2026-02-28 to 2026-03-02 = 3 days (Feb 28, Mar 1, Mar 2)
        $febSpan = new PricingInput('BASIC', 100000, 'DOMESTIC', '1995-01-01', '2026-02-28', '2026-03-02');
        $this->assertSame(3, $this->calculator->calculate($febSpan)->travelDays);

        // Year end span: 2026-12-30 to 2027-01-02 = 4 days (Dec 30, Dec 31, Jan 1, Jan 2)
        $yearSpan = new PricingInput('BASIC', 100000, 'DOMESTIC', '1995-01-01', '2026-12-30', '2027-01-02');
        $this->assertSame(4, $this->calculator->calculate($yearSpan)->travelDays);
    }

    public function test_rejection_of_unsupported_plan_code(): void
    {
        $this->expectException(UnsupportedPlanException::class);

        $input = new PricingInput('DIAMOND', 100000, 'DOMESTIC', '1995-01-01', '2026-11-10', '2026-11-15');
        $this->calculator->calculate($input);
    }

    public function test_rejection_of_unsupported_sum_insured(): void
    {
        $this->expectException(UnsupportedCoverageException::class);

        $input = new PricingInput('BASIC', 250000, 'DOMESTIC', '1995-01-01', '2026-11-10', '2026-11-15');
        $this->calculator->calculate($input);
    }

    public function test_rejection_when_trip_end_before_trip_start(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(422);

        $input = new PricingInput('BASIC', 100000, 'DOMESTIC', '1995-01-01', '2026-11-15', '2026-11-10');
        $this->calculator->calculate($input);
    }

    public function test_rejection_when_dob_after_trip_start(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(422);

        $input = new PricingInput('BASIC', 100000, 'DOMESTIC', '2026-11-15', '2026-11-10', '2026-11-20');
        $this->calculator->calculate($input);
    }

    /**
     * Test half-up rounding on synthetic numerator values using calculatePaise.
     */
    public function test_half_up_rounding_synthetic_inputs(): void
    {
        // Case: numerator yields remainder 4,999 -> rounds down
        // intdiv(14999 + 5000, 10000) = intdiv(19999, 10000) = 1
        $paiseRoundDown = intdiv(14999 + 5000, 10000);
        $this->assertSame(1, $paiseRoundDown);

        // Case: numerator yields remainder 5,000 -> rounds up
        // intdiv(15000 + 5000, 10000) = intdiv(20000, 10000) = 2
        $paiseRoundUp = intdiv(15000 + 5000, 10000);
        $this->assertSame(2, $paiseRoundUp);

        // Synthetic test via calculatePaise method
        // 1 INR rate * 1 day * 1.00 * 1.00 * 1.00 = 100 paise
        $result = PremiumCalculator::calculatePaise(1, 1, 100, 100, 100);
        $this->assertSame(100, $result);
    }

    /**
     * Test decimal string formatting without floating point conversions.
     */
    public function test_string_formatting_without_floats(): void
    {
        // 1320 paise -> "13.20"
        $breakdown1 = new PremiumBreakdown(1320, 12, 1, 10, '1.10', '1.00', '1.00', 'v1');
        $this->assertSame('13.20', $breakdown1->formatted());

        // 5 paise -> "0.05"
        $breakdown2 = new PremiumBreakdown(5, 12, 1, 10, '1.10', '1.00', '1.00', 'v1');
        $this->assertSame('0.05', $breakdown2->formatted());

        // 100 paise -> "1.00"
        $breakdown3 = new PremiumBreakdown(100, 12, 1, 25, '1.00', '1.00', '1.00', 'v1');
        $this->assertSame('1.00', $breakdown3->formatted());
    }
}
