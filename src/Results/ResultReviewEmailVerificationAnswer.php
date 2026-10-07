<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;

/**
 * A moderator's answer to "The time is correct" for the "Your results" e-mail
 * (docs/features/suspicious-time-review.md, "Where they see it"): the time counts again, or it stays set aside with
 * the moderator's note. Told once.
 */
readonly final class ResultReviewEmailVerificationAnswer
{
    public function __construct(
        public string $noticeId,
        public string $puzzleName,
        public null|int $secondsToSolve,
        public DateTimeImmutable $solvedAt,
        public SuspiciousTimeReplyAnswer $answer,
        public null|string $note,
    ) {
    }

    public function countsAgain(): bool
    {
        return $this->answer === SuspiciousTimeReplyAnswer::Trusted;
    }
}
