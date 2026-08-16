<?php

namespace Tests\Feature\Service;

use App\Support\BusinessHours;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The calendar underneath the SLA clock.
 *
 * No database: this is arithmetic, and it is the arithmetic that decides
 * whether every ticket raised on a Friday afternoon is a breach by Monday.
 */
class BusinessHoursTest extends TestCase
{
    protected function hours(array $overrides = []): BusinessHours
    {
        return new BusinessHours(
            $overrides['schedule'] ?? [
                'mon' => [['08:00', '17:00']],
                'tue' => [['08:00', '17:00']],
                'wed' => [['08:00', '17:00']],
                'thu' => [['08:00', '17:00']],
                'fri' => [['08:00', '17:00']],
            ],
            $overrides['timezone'] ?? 'UTC',
            $overrides['holidays'] ?? [],
        );
    }

    public function test_minutes_inside_the_working_day_are_simply_added(): void
    {
        $due = $this->hours()->add(CarbonImmutable::parse('2026-09-14 09:00', 'UTC'), 120);

        $this->assertSame('2026-09-14 11:00', $due->format('Y-m-d H:i'));
    }

    /** The whole reason this class exists. */
    public function test_a_friday_afternoon_ticket_is_not_due_over_the_weekend(): void
    {
        // Friday 16:00, four working hours to resolve: one hour on Friday,
        // three on Monday morning. Wall-clock arithmetic would say Friday 20:00.
        $due = $this->hours()->add(CarbonImmutable::parse('2026-09-11 16:00', 'UTC'), 240);

        $this->assertSame('2026-09-14 11:00', $due->format('Y-m-d H:i'));
    }

    public function test_work_raised_before_opening_starts_counting_at_opening(): void
    {
        $due = $this->hours()->add(CarbonImmutable::parse('2026-09-14 05:30', 'UTC'), 60);

        $this->assertSame('2026-09-14 09:00', $due->format('Y-m-d H:i'));
    }

    public function test_work_raised_after_closing_starts_the_next_morning(): void
    {
        $due = $this->hours()->add(CarbonImmutable::parse('2026-09-14 22:00', 'UTC'), 60);

        $this->assertSame('2026-09-15 09:00', $due->format('Y-m-d H:i'));
    }

    public function test_a_public_holiday_is_skipped(): void
    {
        $hours = $this->hours(['holidays' => ['2026-09-15']]);

        $due = $hours->add(CarbonImmutable::parse('2026-09-14 16:00', 'UTC'), 120);

        $this->assertSame('2026-09-16 09:00', $due->format('Y-m-d H:i'));
    }

    public function test_a_lunch_break_does_not_count(): void
    {
        $hours = $this->hours(['schedule' => [
            'mon' => [['08:00', '12:00'], ['13:00', '17:00']],
        ]]);

        $due = $hours->add(CarbonImmutable::parse('2026-09-14 11:30', 'UTC'), 60);

        $this->assertSame('2026-09-14 13:30', $due->format('Y-m-d H:i'));
    }

    public function test_elapsed_business_minutes_ignore_the_weekend(): void
    {
        $elapsed = $this->hours()->between(
            CarbonImmutable::parse('2026-09-11 16:00', 'UTC'),
            CarbonImmutable::parse('2026-09-14 09:00', 'UTC'),
        );

        // One hour Friday, one hour Monday. Wall clock would say 65 hours.
        $this->assertSame(120, $elapsed);
    }

    public function test_elapsed_is_never_negative(): void
    {
        $elapsed = $this->hours()->between(
            CarbonImmutable::parse('2026-09-14 12:00', 'UTC'),
            CarbonImmutable::parse('2026-09-14 09:00', 'UTC'),
        );

        $this->assertSame(0, $elapsed);
    }

    /**
     * A business day is defined in the business's own timezone. Storing
     * everything in UTC and asking "is it Saturday" of a UTC instant gets
     * this wrong by up to a day for anyone east of Greenwich.
     */
    public function test_the_working_day_is_read_in_the_policy_timezone(): void
    {
        $hours = $this->hours(['timezone' => 'Africa/Douala']);

        // 07:30 UTC is 08:30 in Douala — half an hour into the working day.
        $due = $hours->add(CarbonImmutable::parse('2026-09-14 07:30', 'UTC'), 60);

        $this->assertSame('2026-09-14 08:30', $due->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    public function test_a_continuous_clock_counts_wall_time(): void
    {
        $due = BusinessHours::continuous()->add(CarbonImmutable::parse('2026-09-11 16:00', 'UTC'), 240);

        $this->assertSame('2026-09-11 20:00', $due->format('Y-m-d H:i'));
    }

    /** A policy with no open window at all would otherwise loop forever. */
    public function test_a_schedule_that_never_opens_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        (new BusinessHours([], 'UTC'))->add(CarbonImmutable::parse('2026-09-14 09:00', 'UTC'), 60);
    }
}
