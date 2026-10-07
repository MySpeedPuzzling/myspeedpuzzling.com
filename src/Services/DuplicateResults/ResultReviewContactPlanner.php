<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\ResultReviewCandidate;
use SpeedPuzzling\Web\Value\PlannedResultReviewContact;
use SpeedPuzzling\Web\Value\ResultReviewContactType;

/**
 * Whether a player gets a "Your results" e-mail and what it tells (docs/features/duplicate-results.md, "Contact
 * rules") - pure, the candidate carries everything:
 *
 * - The **first** e-mail (the backlog) goes to everybody with an open Tier A/B case, an automatic removal or a
 *   verification notice, active or not - active players first, dormant ones last. Tier C cases ride along, never
 *   alone.
 * - **Later** e-mails only to players active in the last 3 months, at most one per 7 days, only with something new:
 *   automatic removals always (once - we changed their data), new cases only when the player reacted to their
 *   latest e-mail and no e-mail listed cases in the last 30 days.
 * - **Verification notices** (docs/features/suspicious-time-review.md, "Where they see it") - a time set aside until
 *   it is checked, and a moderator's answer to "The time is correct" - go exactly like removals: always and once,
 *   even to players who ignored earlier e-mails, because we changed how their data counts.
 *
 * Calendar days, not hours: the planning runs at night, the sending during the day.
 */
final class ResultReviewContactPlanner
{
    public const int ACTIVE_MONTHS = 3;
    public const int WEEKLY_DAYS = 7;
    public const int NEW_CASES_DAYS = 30;

    public const int PRIORITY_WEEKLY = 0;
    public const int PRIORITY_FIRST_ACTIVE = 1;
    public const int PRIORITY_FIRST_DORMANT = 2;

    public function plan(ResultReviewCandidate $candidate, DateTimeImmutable $now): null|PlannedResultReviewContact
    {
        $today = $now->setTime(0, 0);
        $active = $candidate->lastActiveOn !== null
            && $candidate->lastActiveOn >= $today->modify('-' . self::ACTIVE_MONTHS . ' months');

        if ($candidate->lastSentAt === null) {
            return $this->contact(
                ResultReviewContactType::First,
                $active ? self::PRIORITY_FIRST_ACTIVE : self::PRIORITY_FIRST_DORMANT,
                $candidate,
                withCases: true,
            );
        }

        if ($active === false || $candidate->lastSentAt->setTime(0, 0) > $today->modify('-' . self::WEEKLY_DAYS . ' days')) {
            return null;
        }

        $mayTellCases = $candidate->lastSentReactedAt !== null
            && (
                $candidate->lastSentWithCasesAt === null
                || $candidate->lastSentWithCasesAt->setTime(0, 0) <= $today->modify('-' . self::NEW_CASES_DAYS . ' days')
            );

        return $this->contact(ResultReviewContactType::Weekly, self::PRIORITY_WEEKLY, $candidate, withCases: $mayTellCases);
    }

    private function contact(
        ResultReviewContactType $type,
        int $priority,
        ResultReviewCandidate $candidate,
        bool $withCases,
    ): null|PlannedResultReviewContact {
        $strongCaseIds = $withCases ? $candidate->strongCaseIds : [];

        // Tier C alone never triggers an e-mail
        if ($strongCaseIds === [] && $candidate->removalIds === [] && $candidate->suspiciousNoticeIds === []) {
            return null;
        }

        return new PlannedResultReviewContact(
            type: $type,
            priority: $priority,
            caseIds: $withCases ? [...$strongCaseIds, ...$candidate->possibleCaseIds] : [],
            removalIds: $candidate->removalIds,
            suspiciousNoticeIds: $candidate->suspiciousNoticeIds,
        );
    }
}
