<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Value\PaceFormCheck;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;

/**
 * The pace check of the add/edit time form (docs/features/suspicious-time-review.md, "Catch it while typing"): the
 * scan's classifier for the time being typed, against the player's own times. The form refuses a raised time until
 * the player answers "Yes, it's right"; the live check (FirstTryCheckController) shows the same notice while typing.
 *
 * The form's inputs are read the way the handlers read them: the date the handler will store, the group around
 * whoever tracked the result (solo / pair / team by the number of people). Null = not judged - no time, an unknown
 * puzzle, an edit that leaves the entry as it is, an edit by a member who did not track the result (the time is
 * judged against the tracker's own times - nothing of them is shown to anybody else), or a failed check
 * (SingleTimeSuspicionCheck fails open).
 *
 * The answer carries the key of the values judged (PaceFormCheck): "Yes, it's right" counts only for them.
 *
 * Without the "another edition" explanation: its lookup compares names against every puzzle of the brand (20-40 ms)
 * and adds nothing to whether the time is asked about - the scan looks for it.
 */
readonly final class SuspiciousTimeFormCheck
{
    public function __construct(
        private SingleTimeSuspicionCheck $singleTimeSuspicionCheck,
        private MistypedYearNormalizer $mistypedYearNormalizer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $groupPlayers The form's co-puzzlers: "#CODE" or a guest name
     */
    public function forNewResult(
        string $viewerPlayerId,
        string $viewerCode,
        string $puzzleId,
        array $groupPlayers,
        null|DateTimeImmutable $solvedAt,
        null|int $seconds,
        // The add form's own result id - a form sent again must not be judged against the copy it saved already
        null|string $timeId = null,
    ): null|PaceFormCheck {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $puzzleId = strtolower($puzzleId);
        $puzzlersCount = self::puzzlersCount($groupPlayers, $viewerCode);

        $assessment = $this->singleTimeSuspicionCheck->forEntry(
            playerId: $viewerPlayerId,
            puzzleId: $puzzleId,
            seconds: $seconds,
            puzzlingType: PuzzlingType::fromPuzzlersCount($puzzlersCount),
            puzzlersCount: $puzzlersCount,
            excludeTimeId: $timeId,
            moment: SolveMoment::of($this->mistypedYearNormalizer->normalizeFinishedAt($solvedAt), $this->clock->now()),
            withOtherEditions: false,
        );

        return $assessment === null ? null : new PaceFormCheck($assessment, self::confirmationKey($puzzleId, $seconds, $solvedAt, $puzzlersCount));
    }

    /**
     * @param array<mixed> $groupPlayers The form's co-puzzlers: "#CODE" or a guest name
     */
    public function forEditedResult(
        string $viewerPlayerId,
        string $viewerCode,
        SolvedPuzzleDetail $time,
        array $groupPlayers,
        null|DateTimeImmutable $solvedAt,
        null|int $seconds,
        // The puzzle picked in the form when the tracker moves the result - null = it stays where it is
        null|string $puzzleId = null,
    ): null|PaceFormCheck {
        // A member who did not track the result is never asked: the expectation is the tracker's, from the tracker's
        // own times - not something to show anybody else
        if ($seconds === null || $seconds <= 0 || $time->playerId !== strtolower($viewerPlayerId)) {
            return null;
        }

        $puzzleId = $puzzleId !== null ? strtolower($puzzleId) : $time->puzzleId;

        // The group around the tracker (EditPuzzleSolvingTimeHandler) - here the viewer
        $puzzlersCount = self::puzzlersCount($groupPlayers, $viewerCode);

        // The same entry as saved (time, puzzle, number of people - what the scan's fingerprint holds): the edit
        // changes something else, and a time a moderator trusted is never asked about again
        if ($seconds === $time->time && $puzzleId === $time->puzzleId && $puzzlersCount === 1 + count($time->players ?? [])) {
            return null;
        }

        $assessment = $this->singleTimeSuspicionCheck->forEntry(
            playerId: $time->playerId,
            puzzleId: $puzzleId,
            seconds: $seconds,
            puzzlingType: PuzzlingType::fromPuzzlersCount($puzzlersCount),
            puzzlersCount: $puzzlersCount,
            excludeTimeId: $time->timeId,
            moment: SolveMoment::of(
                $this->mistypedYearNormalizer->normalizeFinishedAt($solvedAt) ?? $time->finishedAt,
                $time->trackedAt ?? $this->clock->now(),
            ),
            withOtherEditions: false,
        );

        return $assessment === null ? null : new PaceFormCheck($assessment, self::confirmationKey($puzzleId, $seconds, $solvedAt, $puzzlersCount));
    }

    /**
     * The values a pace check judged - the puzzle, the time, the day typed (empty without one) and the number of
     * people. "Yes, it's right" travels as this key (the hidden pace_confirmed) and counts only while the form still
     * holds exactly them.
     */
    public static function confirmationKey(string $puzzleId, int $seconds, null|DateTimeImmutable $solvedAt, int $puzzlersCount): string
    {
        return md5(implode('|', [strtolower($puzzleId), $seconds, $solvedAt?->format('Y-m-d') ?? '', $puzzlersCount]));
    }

    /**
     * What the player compared the time with when answering "Yes, it's right" (SuspiciousTimeConfirmation): their
     * expected time, or - without one - the community's mark the notice named: the median time of a pair/team
     * below the slow floor, the time at the community's 99.9th percentile pace for a player without times of
     * their own. Null when the time was not raised.
     */
    public static function confirmedExpectation(SuspicionAssessment $assessment): null|int
    {
        if ($assessment->isRaised() === false) {
            return null;
        }

        if ($assessment->expectedSeconds !== null) {
            return $assessment->expectedSeconds;
        }

        $median = $assessment->reason(SuspiciousTimeReasonCode::BelowSlowFloor)?->intParam('median');

        if ($median !== null && $median > 0) {
            return $median;
        }

        $beyond = $assessment->reason(SuspiciousTimeReasonCode::BeyondKnownPace);
        $pieces = $beyond?->intParam('pieces');
        $topPpm = $beyond?->param('p999_ppm');

        if ($pieces !== null && is_numeric($topPpm) && (float) $topPpm > 0) {
            return max(1, (int) round($pieces * 60 / (float) $topPpm));
        }

        return null;
    }

    /**
     * The people of the result: the tracker plus every other co-puzzler input, read like PuzzlersGrouping reads
     * them (#code = a registered player, anything else a guest's name, each once, the tracker's own code skipped).
     *
     * @param array<mixed> $groupPlayers
     */
    public static function puzzlersCount(array $groupPlayers, null|string $trackerCode): int
    {
        $trackerCode = $trackerCode !== null ? mb_strtolower(trim($trackerCode, "\# \t\n\r\0")) : null;
        $others = [];

        foreach ($groupPlayers as $input) {
            if (is_string($input) === false) {
                continue;
            }

            $registered = str_starts_with($input, '#');
            $name = mb_strtolower(trim($input, "\# \t\n\r\0"));

            if ($name === '' || ($registered && $name === $trackerCode)) {
                continue;
            }

            $others[($registered ? '#' : '') . $name] = true;
        }

        return 1 + count($others);
    }
}
