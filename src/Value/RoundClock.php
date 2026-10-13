<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;

/**
 * A round's clock as the online rounds read it (docs/features/online-competitions/README.md §5.1) - one answer to "has
 * it started, is it running, is it over", from the round's stopwatch columns at a given moment:
 * - waiting: no start yet
 * - scheduled: a start in the future - the countdown (stopwatch status `scheduled`, or `running` with a future start)
 * - running: started, not stopped, before the time limit
 * - ended: stopped (an online round has no resume - "End now" ends it), or the time limit has passed
 *
 * Only the online code reads it: for an in-person round `stopped` means paused, and the existing stopwatch readers keep
 * their own logic. Moments are epoch milliseconds; the columns hold whole seconds, so a moment is exact once stored.
 * The phones never trust `status` alone - they derive the screen from the moments and their synced clock. The state
 * hash is made of the stored values only (stateHash()).
 */
final readonly class RoundClock
{
    public const string WAITING = 'waiting';
    public const string SCHEDULED = 'scheduled';
    public const string RUNNING = 'running';
    public const string ENDED = 'ended';

    /**
     * @param self::WAITING|self::SCHEDULED|self::RUNNING|self::ENDED $status
     */
    private function __construct(
        public string $status,
        // The column as stored: null, 'scheduled', 'running' or 'stopped' - only the state hash reads it
        public null|string $stopwatchStatus,
        public null|int $startedAtMs,
        public null|int $stoppedAtMs,
        // When the round ends or ended: the stop when it came before the time limit, the time limit otherwise
        public null|int $endsAtMs,
        public int $minutesLimit,
    ) {
    }

    public static function fromRound(CompetitionRound $round, DateTimeImmutable $now): self
    {
        $nowMs = self::milliseconds($now);
        $startedAtMs = $round->stopwatchStartedAt === null ? null : $round->stopwatchStartedAt->getTimestamp() * 1000;
        $stoppedAtMs = $round->stopwatchStoppedAt === null ? null : $round->stopwatchStoppedAt->getTimestamp() * 1000;
        $limitEndsAtMs = $startedAtMs === null ? null : $startedAtMs + $round->minutesLimit * 60_000;
        $stopped = $round->stopwatchStatus === 'stopped';

        $status = match (true) {
            $startedAtMs === null || $limitEndsAtMs === null => self::WAITING,
            $stopped => self::ENDED,
            $startedAtMs > $nowMs => self::SCHEDULED,
            $nowMs >= $limitEndsAtMs => self::ENDED,
            default => self::RUNNING,
        };

        $endsAtMs = $limitEndsAtMs;

        if ($stopped && $stoppedAtMs !== null && $limitEndsAtMs !== null) {
            $endsAtMs = min($stoppedAtMs, $limitEndsAtMs);
        }

        return new self(
            status: $status,
            stopwatchStatus: $round->stopwatchStatus,
            startedAtMs: $startedAtMs,
            stoppedAtMs: $stoppedAtMs,
            endsAtMs: $endsAtMs,
            minutesLimit: $round->minutesLimit,
        );
    }

    public static function milliseconds(DateTimeImmutable $moment): int
    {
        return (int) $moment->format('Uv');
    }

    /**
     * A countdown or a running round - the round a participant's page is about right now
     */
    public function isLive(): bool
    {
        return $this->status === self::SCHEDULED || $this->status === self::RUNNING;
    }

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }

    public function hasEnded(): bool
    {
        return $this->status === self::ENDED;
    }

    /**
     * Heartbeat answers carry it - a phone whose own state has another hash fetches the state again (README §5.3). It
     * changes only with a write: the organiser starts, schedules, cancels or ends the round, or changes its time limit.
     *
     * The formula - the one hash of a round's stopwatch state, keep it in step with every place that publishes one:
     *     substr(sha1(json_encode([stored status, startedAtMs, stoppedAtMs, minutesLimit])), 0, 12)
     * from the stored values only: the column's status (null, 'scheduled', 'running', 'stopped') and the moments in
     * epoch ms (null when not set). Never the derived `status`: that changes at the start moment and at the time limit
     * without any write - with it in the hash every phone's next heartbeat would see a new hash right after the start
     * and all of them would fetch the state in the same second.
     */
    public function stateHash(): string
    {
        return substr(sha1(json_encode([
            $this->stopwatchStatus,
            $this->startedAtMs,
            $this->stoppedAtMs,
            $this->minutesLimit,
        ], JSON_THROW_ON_ERROR)), 0, 12);
    }

    /**
     * @return array{status: self::WAITING|self::SCHEDULED|self::RUNNING|self::ENDED, startedAtMs: null|int, stoppedAtMs: null|int, endsAtMs: null|int, minutesLimit: int, stateHash: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'startedAtMs' => $this->startedAtMs,
            'stoppedAtMs' => $this->stoppedAtMs,
            'endsAtMs' => $this->endsAtMs,
            'minutesLimit' => $this->minutesLimit,
            'stateHash' => $this->stateHash(),
        ];
    }
}
