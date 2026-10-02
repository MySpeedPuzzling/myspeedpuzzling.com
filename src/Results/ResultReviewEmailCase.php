<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;

/**
 * One line of the "Your results" e-mail: a case still open with both copies there. Names nobody - the e-mail has
 * no room for the privacy rules of the review page, so a teammate copy says only that a teammate saved it too.
 */
readonly final class ResultReviewEmailCase
{
    public function __construct(
        public string $caseId,
        public DuplicateTier $tier,
        public DuplicateKind $kind,
        public string $puzzleName,
        public null|int $secondsToSolve,
        public DateTimeImmutable $solvedAt,
    ) {
    }

    public function triggersEmail(): bool
    {
        return $this->tier !== DuplicateTier::Possible;
    }

    /**
     * How the e-mail describes it: `result_review.kind_*`.
     */
    public function lineKind(): string
    {
        return match ($this->kind) {
            DuplicateKind::TeammateCopy => 'teammate_copy',
            DuplicateKind::SoloAndGroup => 'solo_and_group',
            default => 'saved_twice',
        };
    }
}
