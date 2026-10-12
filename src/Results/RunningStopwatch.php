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
 * same numbers (assets/stopwatch_elapsed.js, format() is the twin of self::format()).
 */
readonly final class RunningStopwatch
{
    public const int FRESH_FOR_HOURS = 24;

    // Below 360 px the pill shows m:ss and stays ≤ 70 px wide: the ⏱ icon is shown only next to a short m:ss
    // (under 100 minutes), decided once at render so the pill never changes its width while it ticks
    public const int COMPACT_ICON_MAX_CHARACTERS = 5;

    private const string LAP_FORMAT = '!Y-m-d H:i:s';

    public function __construct(
        public string $stopwatchId,
        public DateTimeImmutable $lapStartedAt,
        // The laps before the running one, in whole seconds
        public int $closedSeconds,
        // When the profile row was read: the render epoch the browser counts on from
        public DateTimeImmutable $readAt,
    ) {
    }

    /**
     * @param null|string $json `{"id", "lap_started_at" ('Y-m-d H:i:s' as Stopwatch::$laps stores it), "closed_seconds"}`
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

        // The zone the laps were written in (LapsArrayDoctrineType reads them the same way)
        $lapStartedAt = DateTimeImmutable::createFromFormat(self::LAP_FORMAT, $lapStart);

        if ($lapStartedAt === false || $lapStartedAt->format('Y-m-d H:i:s') !== $lapStart) {
            return null;
        }

        if ($now->getTimestamp() - $lapStartedAt->getTimestamp() >= self::FRESH_FOR_HOURS * 3600) {
            return null;
        }

        return new self($stopwatchId, $lapStartedAt, max(0, (int) round($closedSeconds)), $now);
    }

    public function elapsedSeconds(DateTimeImmutable $now): int
    {
        return $this->closedSeconds + max(0, $now->getTimestamp() - $this->lapStartedAt->getTimestamp());
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
}
