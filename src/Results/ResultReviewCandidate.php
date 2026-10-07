<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A player who could get a "Your results" e-mail - what there is to tell that no e-mail told yet, and how the
 * earlier e-mails went. ResultReviewContactPlanner decides whether one goes out.
 */
readonly final class ResultReviewCandidate
{
    /**
     * @param list<string> $strongCaseIds open Tier A/B cases, never part of an e-mail
     * @param list<string> $possibleCaseIds open Tier C cases, never part of an e-mail
     * @param list<string> $removalIds copies removed automatically, not undone, never reported
     * @param list<string> $suspiciousNoticeIds marks still in force and moderators' answers, neither told by e-mail
     *     yet (docs/features/suspicious-time-review.md, "Where they see it") - told like removals
     */
    public function __construct(
        public string $playerId,
        public null|DateTimeImmutable $lastActiveOn,
        public null|DateTimeImmutable $lastSentAt,
        // When the player reacted to the latest e-mail they got (null = not yet / ignored)
        public null|DateTimeImmutable $lastSentReactedAt,
        // The latest e-mail that listed any cases
        public null|DateTimeImmutable $lastSentWithCasesAt,
        public array $strongCaseIds,
        public array $possibleCaseIds,
        public array $removalIds,
        public array $suspiciousNoticeIds = [],
    ) {
    }
}
