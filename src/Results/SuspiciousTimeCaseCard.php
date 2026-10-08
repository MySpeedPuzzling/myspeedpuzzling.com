<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseOrigin;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * Everything a moderator needs to decide about one case of the time verification queue
 * (docs/features/suspicious-time-review.md, "Moderator queue"): the time, the expectation and the reasons, the puzzle
 * and the player's numbers. A private player's queued time is shown too (Jan, 2026-10-07) - nothing else of them.
 */
readonly final class SuspiciousTimeCaseCard
{
    /**
     * @param list<SuspiciousTimeReason> $reasons
     * @param list<SuspiciousTimeReason> $reasonsShown
     * @param list<string> $groupMembers names of the pair's/team's people, the tracker included
     * @param list<array{pieces: int, seconds: int, type: string, solves: int}> $baselines the player's baselines by piece count
     * @param list<SuspiciousTimeSameDayResult> $sameDay
     * @param list<SuspiciousTimeCaseNoticeRow> $notices every notice of the case, of every mark
     */
    public function __construct(
        public string $caseId,
        public SuspiciousTimeCaseStatus $status,
        public SuspiciousTimeCaseOrigin $origin,
        public null|SuspicionDirection $direction,
        public null|SuspiciousTimeTier $tier,
        public null|float $score,
        public array $reasons,
        public null|int $expectedSeconds,
        public null|ExpectedTimeSource $expectedSource,
        public null|int $detectorVersion,
        public string $caseFingerprint,
        public string $currentFingerprint,
        public DateTimeImmutable $detectedAt,
        public null|DateTimeImmutable $decidedAt,
        public null|string $decidedByName,
        public null|string $decidedByCode,
        public array $reasonsShown,
        public null|string $moderatorNote,
        public null|DateTimeImmutable $markedAt,
        public null|DateTimeImmutable $playerEditedAt,
        public string $timeId,
        public null|int $seconds,
        public DateTimeImmutable $solvedAt,
        public DateTimeImmutable $trackedAt,
        public PuzzlingType $puzzlingType,
        public int $puzzlersCount,
        public null|string $comment,
        public null|string $finishedPuzzlePhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        public bool $flagged,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|string $manufacturerName,
        public int $puzzleResults,
        public null|int $puzzleMedian,
        public null|int $puzzleFastest,
        public null|float $difficultyScore,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public bool $playerPrivate,
        public null|string $competitionName,
        public null|string $roundName,
        public null|int $leaderboardPlace,
        public int $soloResults,
        public int $groupResults,
        public array $groupMembers,
        public array $baselines,
        public array $sameDay,
        public array $notices,
    ) {
    }

    /**
     * How far off: expected ÷ entered for a fast time, entered ÷ expected for a slow one.
     */
    public function ratio(): null|float
    {
        if ($this->seconds === null || $this->seconds <= 0 || $this->expectedSeconds === null || $this->expectedSeconds <= 0) {
            return null;
        }

        if ($this->direction === SuspicionDirection::Slow) {
            return $this->seconds / $this->expectedSeconds;
        }

        if ($this->direction === SuspicionDirection::Fast) {
            return $this->expectedSeconds / $this->seconds;
        }

        return max($this->expectedSeconds / $this->seconds, $this->seconds / $this->expectedSeconds);
    }

    /**
     * A time judged by the community's slow floor (a pair/team, a new player): what most puzzlers take for the piece
     * count, from its below_slow_floor reason - null for every other case.
     */
    public function communityMedianSeconds(): null|int
    {
        if ($this->expectedSeconds !== null) {
            return null;
        }

        foreach ($this->reasons as $reason) {
            if ($reason->code === SuspiciousTimeReasonCode::BelowSlowFloor) {
                $median = $reason->params['median'] ?? null;

                return is_int($median) && $median > 0 ? $median : null;
            }
        }

        return null;
    }

    /**
     * How many times slower than most puzzlers (communityMedianSeconds()).
     */
    public function communityRatio(): null|float
    {
        $median = $this->communityMedianSeconds();

        return $median !== null && $this->seconds !== null && $this->seconds > 0 ? $this->seconds / $median : null;
    }

    public function isFasterThanExpected(): bool
    {
        return $this->seconds !== null && $this->expectedSeconds !== null && $this->seconds < $this->expectedSeconds;
    }

    /**
     * The time the reasons propose (hours left out, minutes in the hours box) - while the time is still the one it was
     * proposed for.
     */
    public function suggestedSeconds(): null|int
    {
        return SuspiciousTimeReason::suggestionFor($this->reasons, $this->seconds);
    }

    /**
     * A pending case whose time was edited after the scan raised it: its reasons are about the old entry, the next
     * scan judges the new one.
     */
    public function isEditedSinceScan(): bool
    {
        return $this->status === SuspiciousTimeCaseStatus::Pending && $this->caseFingerprint !== $this->currentFingerprint;
    }

    /**
     * The people told about the mark in force.
     *
     * @return list<SuspiciousTimeCaseNoticeRow>
     */
    public function currentNotices(): array
    {
        if ($this->markedAt === null) {
            return [];
        }

        $markedAt = $this->markedAt->getTimestamp();

        return array_values(array_filter(
            $this->notices,
            static fn (SuspiciousTimeCaseNoticeRow $notice): bool => $notice->markedAt->getTimestamp() === $markedAt,
        ));
    }

    /**
     * "The time is correct" of the mark in force without a moderator's answer.
     *
     * @return list<SuspiciousTimeCaseNoticeRow>
     */
    public function openReplies(): array
    {
        return array_values(array_filter(
            $this->currentNotices(),
            static fn (SuspiciousTimeCaseNoticeRow $notice): bool => $notice->isOpenReply(),
        ));
    }

    /**
     * Pair/team results among all of the player's results, in percent.
     */
    public function groupShare(): null|int
    {
        $all = $this->soloResults + $this->groupResults;

        return $all > 0 ? (int) round($this->groupResults / $all * 100) : null;
    }
}
