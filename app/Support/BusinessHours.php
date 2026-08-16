<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * A working calendar, and arithmetic on it.
 *
 * The reason this exists: a ticket raised at 16:30 on a Friday with a
 * four-hour resolution target has not breached at 20:30 on Friday night. It
 * has not breached at all until 11:00 on Monday, because nobody was at work
 * in between. Counting SLA time in wall-clock hours means every Friday
 * afternoon ticket is a failure by Monday morning through nobody's fault,
 * which discredits the whole measure — the desk learns to ignore the board.
 *
 * Two operations are needed and both are the same walk:
 *   add()     — where does N working minutes from here land?
 *   between() — how many working minutes lie between two instants?
 *
 * Deliberately free of the database and of any model, so it can be exercised
 * against a table of awkward dates without a company existing.
 */
class BusinessHours
{
    /** Weekday keys, Monday first, matching Carbon's dayOfWeekIso. */
    protected const DAYS = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

    /**
     * How far forward the walk will look before giving up.
     *
     * A guard rather than a limit: a schedule with one open hour a week still
     * resolves inside a year. Without it, a policy whose only open day is also
     * on the holiday list would spin until the request timed out, and the bug
     * would look like a hung server rather than a bad policy.
     */
    protected const MAX_DAYS = 400;

    /** @var array<string, array<int, array{0: string, 1: string}>> */
    protected array $schedule;

    /** @var array<int, string> */
    protected array $holidays;

    /**
     * @param  array<string, array<int, array{0: string, 1: string}>>  $schedule  ['mon' => [['08:00','17:00']], ...]
     * @param  array<int, string>  $holidays  ['2026-12-25', ...]
     */
    public function __construct(
        array $schedule,
        protected string $timezone = 'UTC',
        array $holidays = [],
        protected bool $continuous = false,
    ) {
        $this->schedule = $this->normalise($schedule);
        $this->holidays = array_values(array_map(
            fn ($day) => CarbonImmutable::parse($day)->toDateString(),
            $holidays,
        ));
    }

    /** A 24/7 contract: the clock never stops, so there is no calendar to consult. */
    public static function continuous(string $timezone = 'UTC'): self
    {
        return new self([], $timezone, [], true);
    }

    /**
     * Where N working minutes from $from lands.
     *
     * Note the boundary convention: minutes that exactly fill a window land on
     * that window's closing time, not on the next morning's opening. The two
     * are the same instant in working time, and closing time is the one a
     * human reading "due 17:00" recognises.
     */
    public function add(DateTimeInterface $from, int $minutes): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);

        if ($this->continuous) {
            return $cursor->addMinutes(max(0, $minutes));
        }

        $remaining = max(0, $minutes);

        for ($day = 0; $day <= self::MAX_DAYS; $day++) {
            $date = $cursor->copy()->addDays($day)->startOfDay();

            foreach ($this->windowsOn($date) as [$opens, $closes]) {
                if ($closes->lessThanOrEqualTo($cursor)) {
                    continue;
                }

                $start = $opens->greaterThan($cursor) ? $opens : $cursor;
                $available = (int) $start->diffInMinutes($closes, absolute: false);

                // Zero minutes is a legitimate target — "respond immediately"
                // — and it resolves to the first moment the desk is open.
                if ($remaining <= $available) {
                    return $start->addMinutes($remaining);
                }

                $remaining -= $available;
            }
        }

        throw new RuntimeException(
            'This service calendar never opens, so no deadline can be worked out from it. '
            .'Give the policy at least one working window, or set its clock to calendar time.'
        );
    }

    /**
     * Working minutes between two instants. Never negative: a pause that
     * appears to end before it began is bad data, and crediting negative time
     * back to a deadline would silently tighten it.
     */
    public function between(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $start = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $end = CarbonImmutable::instance($to)->setTimezone($this->timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        if ($this->continuous) {
            return (int) $start->diffInMinutes($end, absolute: false);
        }

        $minutes = 0;
        $days = (int) $start->startOfDay()->diffInDays($end->startOfDay(), absolute: true);

        for ($day = 0; $day <= min($days, self::MAX_DAYS); $day++) {
            $date = $start->copy()->addDays($day)->startOfDay();

            foreach ($this->windowsOn($date) as [$opens, $closes]) {
                $windowStart = $opens->greaterThan($start) ? $opens : $start;
                $windowEnd = $closes->lessThan($end) ? $closes : $end;

                if ($windowEnd->greaterThan($windowStart)) {
                    $minutes += (int) $windowStart->diffInMinutes($windowEnd, absolute: false);
                }
            }
        }

        return $minutes;
    }

    public function isOpenAt(DateTimeInterface $at): bool
    {
        if ($this->continuous) {
            return true;
        }

        $moment = CarbonImmutable::instance($at)->setTimezone($this->timezone);

        foreach ($this->windowsOn($moment->copy()->startOfDay()) as [$opens, $closes]) {
            if ($moment->greaterThanOrEqualTo($opens) && $moment->lessThan($closes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The open windows on one calendar day, as instants.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    protected function windowsOn(CarbonImmutable $date): array
    {
        if (in_array($date->toDateString(), $this->holidays, true)) {
            return [];
        }

        $windows = [];

        foreach ($this->schedule[self::DAYS[$date->dayOfWeekIso]] ?? [] as [$opens, $closes]) {
            $start = $this->at($date, $opens);
            $end = $this->at($date, $closes);

            // "22:00 to 02:00" is a night shift, not an empty window.
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }

            $windows[] = [$start, $end];
        }

        return $windows;
    }

    protected function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $date->setTime((int) $hour, (int) $minute);
    }

    /**
     * Accepts the shapes a business will actually store: a list of windows per
     * day, or a bare pair for the common single-window day.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    protected function normalise(array $schedule): array
    {
        $normalised = [];

        foreach ($schedule as $day => $windows) {
            $key = strtolower(substr((string) $day, 0, 3));

            if (! in_array($key, self::DAYS, true) || ! is_array($windows) || $windows === []) {
                continue;
            }

            // ['08:00', '17:00'] rather than [['08:00', '17:00']].
            if (is_string($windows[0] ?? null)) {
                $windows = [$windows];
            }

            foreach ($windows as $window) {
                if (is_array($window) && isset($window[0], $window[1])) {
                    $normalised[$key][] = [(string) $window[0], (string) $window[1]];
                }
            }
        }

        return $normalised;
    }
}
