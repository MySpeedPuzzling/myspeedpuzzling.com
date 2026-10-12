<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Ramsey\Uuid\Uuid;

/**
 * The viewer's running stopwatch behind the top bar's pill (docs/features/navigation README D7, ADD-03): status running,
 * its last lap started less than FRESH_FOR_HOURS ago, the most recent one. It rides on the viewer's own profile row
 * (GetPlayerProfile::byUserId(), `running_stopwatch`), which picks the running stopwatch with the latest lap start; the
 * freshness is decided here with the clock - never with SQL now() - so it is a stale stopwatch, not a missing one, that
 * shows nothing. Paused, finished and stale stopwatches show nothing.
 *
 * The pill's digits are rendered by the server from `readAt` (when the row was read) and tick on in the browser from the
 * same numbers (assets/stopwatch_elapsed.js, format() is the twin of self::format()). Laps are stored to the millisecond
 * since 2026-10-12 (LapsArrayDoctrineType, whole seconds before): the elapsed time is counted in milliseconds and cut to
 * whole seconds once, at the end - the digits the stopwatch page shows (stopwatch_controller.js) - never per lap.
 */
readonly final class RunningStopwatch
{
    public const int FRESH_FOR_HOURS = 24;

    // Below 360 px the pill shows m:ss and stays ≤ 70 px wide: the ⏱ icon is shown only next to a short m:ss
    // (under 100 minutes), decided once at render so the pill never changes its width while it ticks
    public const int COMPACT_ICON_MAX_CHARACTERS = 5;

    // As LapsArrayDoctrineType writes a lap: to the millisecond, or in whole seconds before 2026-10-12
    private const array LAP_FORMATS = ['Y-m-d H:i:s.v', 'Y-m-d H:i:s'];

    public function __construct(
        public string $stopwatchId,
        public DateTimeImmutable $lapStartedAt,
        // The laps before the running one
        public int $closedMilliseconds,
        // When the profile row was read: the render epoch the browser counts on from
        public DateTimeImmutable $readAt,
    ) {
    }

    /**
     * @param null|string $json `{"id", "lap_started_at" (as Stopwatch::$laps stores it), "closed_seconds" (fractions allowed)}`
     */
    public static function fromJson(null|string $json, DateTimeImmutable $now): null|self
    {
        if ($json === null) {
            return null;
        }

        try {
            $data = Json::decode($json, true);
        } catch (JsonException) {
            return null;
        }

        if (is_array($data) === false) {
            return null;
        }

        $stopwatchId = $data['id'] ?? null;
        $lapStart = $data['lap_started_at'] ?? null;
        $closedSeconds = $data['closed_seconds'] ?? 0;

        if (is_string($stopwatchId) === false || Uuid::isValid($stopwatchId) === false) {
            return null;
        }

        if (is_string($lapStart) === false || (is_int($closedSeconds) === false && is_float($closedSeconds) === false)) {
            return null;
        }

        $lapStartedAt = self::parseLapMoment($lapStart);

        if ($lapStartedAt === null) {
            return null;
        }

        if ($now->getTimestamp() - $lapStartedAt->getTimestamp() >= self::FRESH_FOR_HOURS * 3600) {
            return null;
        }

        return new self($stopwatchId, $lapStartedAt, max(0, (int) round($closedSeconds * 1000)), $now);
    }

    /**
     * Whole seconds: the closed laps and the running one in milliseconds, cut once
     */
    public function elapsedSeconds(DateTimeImmutable $now): int
    {
        return intdiv($this->closedMilliseconds + max(0, self::epochMilliseconds($now) - self::epochMilliseconds($this->lapStartedAt)), 1000);
    }

    /**
     * The numbers the browser counts on from (templates/navigation/_stopwatch_elapsed.html.twig): unix seconds with
     * milliseconds - when the running lap started and when the row was read - and the closed laps' seconds
     */
    public function lapStartedEpoch(): string
    {
        return $this->lapStartedAt->format('U.v');
    }

    public function readEpoch(): string
    {
        return $this->readAt->format('U.v');
    }

    public function closedSecondsText(): string
    {
        return sprintf('%d.%03d', intdiv($this->closedMilliseconds, 1000), $this->closedMilliseconds % 1000);
    }

    /**
     * The digits as the server renders them, at `readAt`.
     */
    public function textAtRead(bool $compact): string
    {
        return self::format($this->elapsedSeconds($this->readAt), $compact);
    }

    /**
     * The room the digits get, fixed at render: the rendered text plus one more digit, every digit a 0 (tabular digits
     * are all as wide as a 0). Laid under the digits invisibly, so 9:59 → 10:00 or 99:59 → 100:00 never widens the
     * pill - the exact width in whatever font renders it (a width in `ch` would count each colon as a digit).
     */
    public function roomAtRead(bool $compact): string
    {
        return '0' . (string) preg_replace('/\d/', '0', $this->textAtRead($compact));
    }

    /**
     * Below 360 px: the ⏱ icon next to the digits only while m:ss is short (see COMPACT_ICON_MAX_CHARACTERS).
     */
    public function showsCompactIcon(): bool
    {
        return mb_strlen($this->textAtRead(true)) <= self::COMPACT_ICON_MAX_CHARACTERS;
    }

    /**
     * Full `h:mm:ss`; compact `m:ss` with the minutes uncapped (99:59, 100:00, 999:59). The twin of format() in
     * assets/stopwatch_elapsed.js (StopwatchElapsedFormatTest).
     */
    public static function format(int $seconds, bool $compact): string
    {
        $seconds = max(0, $seconds);

        if ($compact) {
            return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * A lap moment as LapsArrayDoctrineType wrote it, in the zone it was written in (the type reads it the same way);
     * null for anything else
     */
    private static function parseLapMoment(string $value): null|DateTimeImmutable
    {
        foreach (self::LAP_FORMATS as $format) {
            $moment = DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($moment !== false && $moment->format($format) === $value) {
                return $moment;
            }
        }

        return null;
    }

    private static function epochMilliseconds(DateTimeImmutable $moment): int
    {
        return (int) $moment->format('Uv');
    }
}
