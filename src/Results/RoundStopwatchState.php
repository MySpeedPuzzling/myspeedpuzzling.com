<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;

/**
 * A round's stopwatch as one payload (docs/features/competitions-management/README.md "Round Stopwatch") - the state
 * endpoint (RoundStopwatchStateController, which adds `serverNow` / `serverNowMs`), every Mercure update on the public
 * topic `/round-stopwatch/{roundId}` (RoundStopwatchLiveUpdates) and the stopwatch pages' first render.
 *
 * - `status`: null | scheduled | running | stopped AT `asOfMs` (RoundStopwatchStatus::at() - a scheduled start that has
 *   passed is running). A client turns `scheduled` into `running` itself once `startedAtMs` comes.
 * - `startedAt` / `stoppedAt` / `minutesLimit`: the original fields (ATOM), kept for the pages loaded before this shape.
 * - `startedAtMs` / `stoppedAtMs`: the same moments in epoch milliseconds (whole seconds - TIMESTAMP(0) columns).
 * - `asOfMs`: when the row was read - a client keeps the state with the newest `asOfMs`, so a slow fetch never
 *   overwrites a newer push.
 * - `stateHash`: short, the same for the same stored state - "has anything changed since?" without the whole state.
 *
 * Never a `type` field: the live entry tells stopwatch updates from official results ones by its absence.
 */
readonly final class RoundStopwatchState implements \JsonSerializable
{
    public function __construct(
        public string $roundId,
        // As stored: null | scheduled | running | stopped
        public null|string $storedStatus,
        public null|DateTimeImmutable $startedAt,
        public null|DateTimeImmutable $stoppedAt,
        public int $minutesLimit,
        // When the row was read
        public DateTimeImmutable $asOf,
    ) {
    }

    public static function ofRound(CompetitionRound $round, DateTimeImmutable $asOf): self
    {
        return new self(
            roundId: $round->id->toString(),
            storedStatus: $round->stopwatchStatus,
            startedAt: $round->stopwatchStartedAt,
            stoppedAt: $round->stopwatchStoppedAt,
            minutesLimit: $round->minutesLimit,
            asOf: $asOf,
        );
    }

    /**
     * The status at asOf: null | scheduled | running | stopped.
     */
    public function status(): null|string
    {
        return RoundStopwatchStatus::at($this->storedStatus, $this->startedAt, $this->asOf);
    }

    public function isCountingDown(): bool
    {
        return $this->status() === RoundStopwatchStatus::SCHEDULED;
    }

    public function startedAtMs(): null|int
    {
        return $this->startedAt !== null ? self::milliseconds($this->startedAt) : null;
    }

    public function stoppedAtMs(): null|int
    {
        return $this->stoppedAt !== null ? self::milliseconds($this->stoppedAt) : null;
    }

    public function asOfMs(): int
    {
        return self::milliseconds($this->asOf);
    }

    /**
     * Of the stored state only - the moment a scheduled start passes changes nothing stored, so nothing here.
     */
    public function stateHash(): string
    {
        return substr(hash('xxh128', implode('|', [
            $this->storedStatus ?? '',
            (string) $this->startedAtMs(),
            (string) $this->stoppedAtMs(),
            (string) $this->minutesLimit,
        ])), 0, 16);
    }

    /**
     * @return array{roundId: string, status: null|string, startedAt: null|string, stoppedAt: null|string, minutesLimit: int, startedAtMs: null|int, stoppedAtMs: null|int, asOfMs: int, stateHash: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'roundId' => $this->roundId,
            'status' => $this->status(),
            'startedAt' => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'stoppedAt' => $this->stoppedAt?->format(\DateTimeInterface::ATOM),
            'minutesLimit' => $this->minutesLimit,
            'startedAtMs' => $this->startedAtMs(),
            'stoppedAtMs' => $this->stoppedAtMs(),
            'asOfMs' => $this->asOfMs(),
            'stateHash' => $this->stateHash(),
        ];
    }

    public static function milliseconds(DateTimeImmutable $moment): int
    {
        return (int) $moment->format('Uv');
    }
}
