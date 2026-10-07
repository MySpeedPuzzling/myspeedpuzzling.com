<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;

/**
 * One of the player's results awaiting verification, as the review page shows it (docs/features/suspicious-time-review.md,
 * "Where they see it"): the mark, the reasons the moderator chose for the player, the player's reaction and a
 * moderator's answer to it - all of this person's notice for the mark.
 */
readonly final class PlayerSuspiciousTime
{
    /**
     * @param list<SuspiciousTimeReason> $reasonsShown
     */
    public function __construct(
        public string $caseId,
        public string $timeId,
        // Whoever saved the result - only they may move it to another puzzle (docs/features/group-time-editing.md)
        public string $trackerId,
        public FirstTryPuzzle $puzzle,
        public null|int $secondsToSolve,
        public DateTimeImmutable $solvedAt,
        public PuzzlingType $puzzlingType,
        public array $reasonsShown,
        public null|string $moderatorNote,
        public DateTimeImmutable $markedAt,
        public null|SuspiciousTimeResponse $response,
        public null|string $responseText,
        public null|SuspiciousTimeReplyAnswer $answer,
        public null|string $answerNote,
        public null|DateTimeImmutable $answeredAt,
    ) {
    }

    /**
     * The time a shown reason suggests (hours left out / minutes in the hours box) - "Fix the time" brings it into
     * the edit form. Only while the result still holds the time it was suggested for.
     */
    public function suggestedSeconds(): null|int
    {
        return SuspiciousTimeReason::suggestionFor($this->reasonsShown, $this->secondsToSolve);
    }

    /**
     * A shown reason says it may have been a pair or team result.
     */
    public function suggestsGroup(): bool
    {
        return $this->hasShownReason(SuspiciousTimeReasonCode::TeammatesSavedGroup)
            || $this->hasShownReason(SuspiciousTimeReasonCode::CommentMentionsGroup);
    }

    /**
     * A shown reason says it may have been another edition of the puzzle.
     */
    public function suggestsOtherEdition(): bool
    {
        return $this->hasShownReason(SuspiciousTimeReasonCode::OtherEdition);
    }

    public function isTrackedBy(string $playerId): bool
    {
        return $this->trackerId === strtolower($playerId);
    }

    /**
     * "The time is correct" was sent and no moderator has answered it yet.
     */
    public function isAwaitingModerator(): bool
    {
        return $this->response === SuspiciousTimeResponse::SaysCorrect && $this->answer === null;
    }

    /**
     * "The time is correct" goes to the moderators once per mark.
     */
    public function canSayCorrect(): bool
    {
        return $this->response !== SuspiciousTimeResponse::SaysCorrect;
    }

    public function canLeaveAsItIs(): bool
    {
        return $this->response === null || $this->response === SuspiciousTimeResponse::Fixed;
    }

    private function hasShownReason(SuspiciousTimeReasonCode $code): bool
    {
        foreach ($this->reasonsShown as $reason) {
            if ($reason->code === $code) {
                return true;
            }
        }

        return false;
    }
}
