<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DuplicateResults;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\ResultReviewCandidate;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewContactPlanner;
use SpeedPuzzling\Web\Value\PlannedResultReviewContact;
use SpeedPuzzling\Web\Value\ResultReviewContactType;

/**
 * The contact rules of the "Your results" e-mail (docs/features/duplicate-results.md, "Contact rules").
 */
final class ResultReviewContactPlannerTest extends TestCase
{
    private const string NOW = '2026-10-02 04:30:00';

    public function testTheFirstEmailGoesToAnActivePlayerFirst(): void
    {
        $plan = $this->plan($this->candidate(lastActiveOn: '2026-09-30', strong: ['b1'], possible: ['c1'], removals: ['r1']));

        self::assertNotNull($plan);
        self::assertSame(ResultReviewContactType::First, $plan->type);
        self::assertSame(ResultReviewContactPlanner::PRIORITY_FIRST_ACTIVE, $plan->priority);
        self::assertSame(['b1', 'c1'], $plan->caseIds);
        self::assertSame(['r1'], $plan->removalIds);
    }

    public function testTheFirstEmailGoesToDormantPlayersToo(): void
    {
        $dormant = $this->plan($this->candidate(lastActiveOn: '2026-06-01', strong: ['b1']));
        $neverSeen = $this->plan($this->candidate(lastActiveOn: null, strong: ['b1']));

        self::assertNotNull($dormant);
        self::assertSame(ResultReviewContactPlanner::PRIORITY_FIRST_DORMANT, $dormant->priority);
        self::assertNotNull($neverSeen);
        self::assertSame(ResultReviewContactPlanner::PRIORITY_FIRST_DORMANT, $neverSeen->priority);
    }

    public function testTierCAloneNeverTriggersAnEmail(): void
    {
        self::assertNull($this->plan($this->candidate(lastActiveOn: '2026-10-01', possible: ['c1'])));

        // ...but rides along with a removal
        $plan = $this->plan($this->candidate(lastActiveOn: '2026-10-01', possible: ['c1'], removals: ['r1']));
        self::assertNotNull($plan);
        self::assertSame(['c1'], $plan->caseIds);
    }

    public function testLaterEmailsOnlyToActivePlayers(): void
    {
        self::assertNull($this->plan($this->candidate(
            lastActiveOn: '2026-06-30',
            lastSentAt: '2026-08-01 09:00:00',
            reactedAt: '2026-08-01 10:00:00',
            removals: ['r1'],
        )));
    }

    public function testAtMostOneEmailPerSevenDays(): void
    {
        $candidate = fn (string $lastSentAt): ResultReviewCandidate => $this->candidate(
            lastActiveOn: '2026-10-01',
            lastSentAt: $lastSentAt,
            removals: ['r1'],
        );

        self::assertNull($this->plan($candidate('2026-09-26 20:00:00')));
        self::assertNotNull($this->plan($candidate('2026-09-25 20:00:00')));
    }

    public function testRemovalsAreToldEvenToPlayersWhoIgnoredTheLastEmail(): void
    {
        $plan = $this->plan($this->candidate(
            lastActiveOn: '2026-10-01',
            lastSentAt: '2026-09-01 09:00:00',
            reactedAt: null,
            strong: ['b1'],
            removals: ['r1'],
        ));

        self::assertNotNull($plan);
        self::assertSame(ResultReviewContactType::Weekly, $plan->type);
        self::assertSame(ResultReviewContactPlanner::PRIORITY_WEEKLY, $plan->priority);
        // No new cases for somebody who ignored us
        self::assertSame([], $plan->caseIds);
        self::assertSame(['r1'], $plan->removalIds);
    }

    public function testNewCasesOnlyAfterAReaction(): void
    {
        self::assertNull($this->plan($this->candidate(
            lastActiveOn: '2026-10-01',
            lastSentAt: '2026-08-01 09:00:00',
            reactedAt: null,
            lastSentWithCasesAt: '2026-08-01 09:00:00',
            strong: ['b2'],
        )));

        $plan = $this->plan($this->candidate(
            lastActiveOn: '2026-10-01',
            lastSentAt: '2026-08-01 09:00:00',
            reactedAt: '2026-08-02 18:00:00',
            lastSentWithCasesAt: '2026-08-01 09:00:00',
            strong: ['b2'],
            possible: ['c2'],
        ));

        self::assertNotNull($plan);
        self::assertSame(ResultReviewContactType::Weekly, $plan->type);
        self::assertSame(['b2', 'c2'], $plan->caseIds);
    }

    public function testNewCasesAtMostOnceIn30Days(): void
    {
        $candidate = fn (string $lastSentWithCasesAt): ResultReviewCandidate => $this->candidate(
            lastActiveOn: '2026-10-01',
            lastSentAt: '2026-09-20 09:00:00',
            reactedAt: '2026-09-20 12:00:00',
            lastSentWithCasesAt: $lastSentWithCasesAt,
            strong: ['b2'],
        );

        self::assertNull($this->plan($candidate('2026-09-03 09:00:00')));
        self::assertNotNull($this->plan($candidate('2026-09-02 09:00:00')));
    }

    private function plan(ResultReviewCandidate $candidate): null|PlannedResultReviewContact
    {
        return (new ResultReviewContactPlanner())->plan($candidate, new DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<string> $strong
     * @param list<string> $possible
     * @param list<string> $removals
     */
    private function candidate(
        null|string $lastActiveOn,
        null|string $lastSentAt = null,
        null|string $reactedAt = null,
        null|string $lastSentWithCasesAt = null,
        array $strong = [],
        array $possible = [],
        array $removals = [],
    ): ResultReviewCandidate {
        $date = static fn (null|string $value): null|DateTimeImmutable => $value !== null ? new DateTimeImmutable($value) : null;

        return new ResultReviewCandidate(
            playerId: '018d0021-0000-0000-0000-000000000001',
            lastActiveOn: $date($lastActiveOn),
            lastSentAt: $date($lastSentAt),
            lastSentReactedAt: $date($reactedAt),
            lastSentWithCasesAt: $date($lastSentWithCasesAt),
            strongCaseIds: $strong,
            possibleCaseIds: $possible,
            removalIds: $removals,
        );
    }
}
