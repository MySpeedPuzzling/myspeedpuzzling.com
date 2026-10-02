<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\FirstTry;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\ResultEntryCheck;

/**
 * The first-try rules and the "same time already saved" check for what the add/edit forms hold - the forms
 * themselves and the live check ask here, so both say the same thing. The co-puzzler inputs are read the way the
 * handlers read them (#code = a registered player), nothing gets created.
 */
readonly final class FirstTryFormCheck
{
    public function __construct(
        private FirstTryAssessor $assessor,
        private PuzzlersGrouping $puzzlersGrouping,
        private MistypedYearNormalizer $mistypedYearNormalizer,
    ) {
    }

    /**
     * @param array<string> $groupPlayers
     */
    public function forNewResult(
        string $viewerPlayerId,
        string $puzzleId,
        array $groupPlayers,
        null|DateTimeImmutable $solvedAt,
        bool $firstAttempt,
        null|int $secondsToSolve,
        // The add form's own result id (docs/features/duplicate-results.md, Layer 1)
        null|string $timeId = null,
    ): ResultEntryCheck {
        return $this->assessor->check(new FirstTryEntry(
            actorPlayerId: $viewerPlayerId,
            puzzleId: $puzzleId,
            memberPlayerIds: $this->members($viewerPlayerId, $groupPlayers),
            // The date the handler will store
            solvedAt: $this->mistypedYearNormalizer->normalizeFinishedAt($solvedAt),
            secondsToSolve: $secondsToSolve,
            newTimeId: $timeId,
        ), $firstAttempt);
    }

    /**
     * @param array<string> $groupPlayers
     */
    public function forEditedResult(
        string $viewerPlayerId,
        SolvedPuzzleDetail $time,
        array $groupPlayers,
        null|DateTimeImmutable $solvedAt,
        bool $firstAttempt,
        null|int $secondsToSolve,
        // The puzzle picked in the form when the tracker moves the result (docs/features/duplicate-results.md,
        // Layer 4) - null = it stays where it is
        null|string $puzzleId = null,
    ): ResultEntryCheck {
        $puzzleId = $puzzleId !== null ? strtolower($puzzleId) : $time->puzzleId;

        // On another puzzle the result is new there: nothing from before the edit is tolerated
        if ($puzzleId !== $time->puzzleId) {
            return $this->assessor->check(new FirstTryEntry(
                actorPlayerId: $viewerPlayerId,
                puzzleId: $puzzleId,
                memberPlayerIds: $this->members($time->playerId, $groupPlayers),
                solvedAt: $this->mistypedYearNormalizer->normalizeFinishedAt($solvedAt) ?? $time->finishedAt,
                editedTimeId: $time->timeId,
                secondsToSolve: $secondsToSolve,
            ), $firstAttempt);
        }

        // The group is always the tracker's, whoever edits it (EditPuzzleSolvingTimeHandler)
        $previousMembers = [$time->playerId];

        foreach ($time->players ?? [] as $puzzler) {
            if ($puzzler->playerId !== null) {
                $previousMembers[] = $puzzler->playerId;
            }
        }

        return $this->assessor->check(new FirstTryEntry(
            actorPlayerId: $viewerPlayerId,
            puzzleId: $time->puzzleId,
            memberPlayerIds: $this->members($time->playerId, $groupPlayers),
            solvedAt: $this->mistypedYearNormalizer->normalizeFinishedAt($solvedAt) ?? $time->finishedAt,
            editedTimeId: $time->timeId,
            previouslyFirstAttempt: $time->firstAttempt,
            previousMemberPlayerIds: $previousMembers,
            secondsToSolve: $secondsToSolve,
            previousSecondsToSolve: $time->time,
            previousSolvedAt: $time->finishedAt,
        ), $firstAttempt);
    }

    /**
     * @param array<string> $groupPlayers
     * @return list<string>
     */
    private function members(string $trackerPlayerId, array $groupPlayers): array
    {
        return array_values(array_unique([
            strtolower($trackerPlayerId),
            ...array_map('strtolower', $this->puzzlersGrouping->registeredPlayerIds($groupPlayers)),
        ]));
    }
}
