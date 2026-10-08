<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The days an occurrence (a one-time event or an edition) takes place on (docs/features/events-page/README.md,
 * "Dates") - the one rule of the events page, its archive, the archive years in the sitemap and "You organize", the
 * same for one-time events and editions. Days are date-only values at 00:00 UTC.
 *
 * Rounds are grouped into sessions by their start day in their own zone (an evening round in Toronto is that evening's
 * date, not the next UTC day): round days at most SESSION_GAP_DAYS apart are one session, so a Friday-Sunday
 * championship stays one even without a Saturday round; so are all rounds inside a declared span (date_from..date_to)
 * of at most SHORT_SPAN_DAYS. Rounds on separate days - a monthly online competition inside one edition - are one
 * dated occurrence per session (sessions()), each with its own days and status.
 *
 * One session (the common case) or no rounds: dated by the first round's day, else date_from (else date_to); the end
 * is the later of date_to and the last round's day, kept only when after the start.
 *
 * A span over LONG_SPAN_DAYS that its rounds do not define - no rounds, or date_to more than LONG_SPAN_DAYS after the
 * last round - is never live: while it runs it is ongoing (status()).
 */
readonly final class OccurrenceDates
{
    // round days at most this far apart are one session (Friday and Sunday without a Saturday round)
    public const int SESSION_GAP_DAYS = 2;
    // every round inside a declared span this short is one session
    public const int SHORT_SPAN_DAYS = 7;
    // a span running longer than this beyond its rounds is ongoing, not live
    public const int LONG_SPAN_DAYS = 31;

    public function __construct(
        public null|DateTimeImmutable $start,
        public null|DateTimeImmutable $end,
        // the day of the last round dating it; null without rounds
        public null|DateTimeImmutable $lastRoundDay = null,
        // only when the occurrence has two or more sessions
        public null|OccurrenceSession $session = null,
    ) {
    }

    /**
     * Every dated occurrence of one competition: one, or one per session when its rounds fall on separate days.
     *
     * @param list<OccurrenceRound> $rounds
     *
     * @return non-empty-list<self>
     */
    public static function sessions(null|DateTimeImmutable $dateFrom, null|DateTimeImmutable $dateTo, array $rounds): array
    {
        $groups = self::roundGroups($rounds, self::dayOf($dateFrom ?? $dateTo), self::dayOf($dateTo ?? $dateFrom));

        if (count($groups) >= 2) {
            $sessions = [];

            foreach ($groups as $index => $group) {
                $first = $group[0];
                $last = $group[count($group) - 1];

                $sessions[] = new self(
                    $first['day'],
                    $last['day'] > $first['day'] ? $last['day'] : null,
                    $last['day'],
                    new OccurrenceSession(
                        index: $index,
                        count: count($groups),
                        firstRoundId: $first['round']->id,
                        label: count($group) === 1 ? $first['round']->name : null,
                    ),
                );
            }

            return $sessions;
        }

        $group = $groups[0] ?? [];
        $start = $group !== [] ? $group[0]['day'] : self::dayOf($dateFrom ?? $dateTo);

        if ($start === null) {
            return [new self(null, null)];
        }

        $lastRoundDay = $group !== [] ? $group[count($group) - 1]['day'] : null;
        $end = self::dayOf($dateTo);

        if ($lastRoundDay !== null && ($end === null || $lastRoundDay > $end)) {
            $end = $lastRoundDay;
        }

        return [new self($start, $end !== null && $end > $start ? $end : null, $lastRoundDay)];
    }

    /**
     * The one of an occurrence's sessions that stands for it where it is listed once ("You organize"): the first that
     * is not over, else the last.
     *
     * @param non-empty-list<self> $sessions in date order
     */
    public static function current(array $sessions, DateTimeImmutable $today, bool $isEdition, bool $isOnline): self
    {
        foreach ($sessions as $session) {
            if ($session->status($today, $isEdition, $isOnline) !== EventOccurrenceStatus::Past) {
                return $session;
            }
        }

        return $sessions[count($sessions) - 1];
    }

    /**
     * The calendar day of a stored date (its date part as stored), at 00:00 UTC.
     */
    public static function dayOf(null|DateTimeImmutable $value): null|DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * The calendar day an instant falls on in $zone, at 00:00 UTC.
     */
    public static function localDay(DateTimeImmutable $instant, string $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($instant->setTimezone(new DateTimeZone($zone))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * Today's date (UTC), at 00:00 UTC - "today" of the events page is the server's UTC date.
     */
    public static function today(DateTimeImmutable $now): DateTimeImmutable
    {
        return new DateTimeImmutable($now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * A span over LONG_SPAN_DAYS its rounds do not define: no rounds, or running more than LONG_SPAN_DAYS past the last
     * one (a 14-month event with one opening round)
     */
    public function isLongSpan(): bool
    {
        if ($this->start === null || $this->end === null || (int) $this->start->diff($this->end)->days <= self::LONG_SPAN_DAYS) {
            return false;
        }

        return $this->lastRoundDay === null || (int) $this->lastRoundDay->diff($this->end)->days > self::LONG_SPAN_DAYS;
    }

    public function status(DateTimeImmutable $today, bool $isEdition, bool $isOnline): EventOccurrenceStatus
    {
        if ($this->start === null) {
            if ($isEdition) {
                return EventOccurrenceStatus::DateNotSet;
            }

            return $isOnline ? EventOccurrenceStatus::Ongoing : EventOccurrenceStatus::Tba;
        }

        $day = self::today($today);

        if (($this->end ?? $this->start) < $day) {
            return EventOccurrenceStatus::Past;
        }

        if ($this->start > $day) {
            return EventOccurrenceStatus::Upcoming;
        }

        return $this->isLongSpan() ? EventOccurrenceStatus::Ongoing : EventOccurrenceStatus::Live;
    }

    /**
     * The rounds grouped into sessions: by local start day, a new session after a gap of more than SESSION_GAP_DAYS;
     * then the sessions inside a declared span of at most SHORT_SPAN_DAYS are one.
     *
     * @param list<OccurrenceRound> $rounds
     *
     * @return list<non-empty-list<array{round: OccurrenceRound, day: DateTimeImmutable}>>
     */
    private static function roundGroups(array $rounds, null|DateTimeImmutable $declaredStart, null|DateTimeImmutable $declaredEnd): array
    {
        $days = array_map(static fn (OccurrenceRound $round): array => ['round' => $round, 'day' => $round->localDay()], $rounds);

        usort($days, static fn (array $a, array $b): int => $a['day'] <=> $b['day'] ?: $a['round']->startsAt <=> $b['round']->startsAt);

        $groups = [];
        $current = [];

        foreach ($days as $item) {
            if ($current !== [] && (int) $current[count($current) - 1]['day']->diff($item['day'])->days > self::SESSION_GAP_DAYS) {
                $groups[] = $current;
                $current = [];
            }

            $current[] = $item;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        if ($declaredStart === null || $declaredEnd === null || (int) $declaredStart->diff($declaredEnd)->days > self::SHORT_SPAN_DAYS) {
            return $groups;
        }

        $merged = [];
        $previousInside = false;

        foreach ($groups as $group) {
            $inside = $group[0]['day'] >= $declaredStart && $group[count($group) - 1]['day'] <= $declaredEnd;

            if ($inside && $previousInside) {
                $merged[count($merged) - 1] = [...$merged[count($merged) - 1], ...$group];
            } else {
                $merged[] = $group;
            }

            $previousInside = $inside;
        }

        return array_values($merged);
    }
}
