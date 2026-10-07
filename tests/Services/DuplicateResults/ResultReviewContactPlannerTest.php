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
 * The contact rules of the "Your results" e-mail (docs/features/duplicate-results.md, "Contact rules"); verification
 * notices go like removals (docs/features/suspicious-time-review.md, "Where they see it").
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

    public function testAVerificationNoticeAloneTriggersTheFirstEmailActiveOrNot(): void
    {
        $active = $this->plan($this->candidate(lastActiveOn: '2026-09-30', notices: ['n1', 'n2']));
        $dormant = $this->plan($this->candidate(lastActiveOn: null, notices: ['n1']));

        self::assertNotNull($active);
        self::assertSame(ResultReviewContactType::First, $active->type);
        self::assertSame(ResultReviewContactPlanner::PRIORITY_FIRST_ACTIVE, $active->priority);
        self::assertSame(['n1', 'n2'], $active->suspiciousNoticeIds);
        self::assertSame([], $active->caseIds);
        self::assertSame([], $active->removalIds);

        self::assertNotNull($dormant);
        self::assertSame(ResultReviewContactPlanner::PRIORITY_FIRST_DORMANT, $dormant->priority);
        self::assertSame(['n1'], $dormant->suspiciousNoticeIds);
    }

    public function testTheFirstEmailCarriesCasesRemovalsAndNoticesTogether(): void
    {
        $plan = $this->plan($this->candidate(lastActiveOn: '2026-09-30', strong: ['b1'], possible: ['c1'], removals: ['r1'], notices: ['n1']));

        self::assertNotNull($plan);
        self::assertSame(['b1', 'c1'], $plan->caseIds);
        self::assertSame(['r1'], $plan->removalIds);
        self::assertSame(['n1'], $plan->suspiciousNoticeIds);
    }

    public function testTierCRidesAlongWithANoticeLikeWithARemoval(): void
    {
        $plan = $this->plan($this->candidate(lastActiveOn: '2026-10-01', possible: ['c1'], notices: ['n1']));

        self::assertNotNull($plan);
        self::assertSame(['c1'], $plan->caseIds);
        self::assertSame(['n1'], $plan->suspiciousNoticeIds);
    }

    public function testVerificationNoticesAreToldEvenToPlayersWhoIgnoredTheLastEmail(): void
    {
        $plan = $this->plan($this->candidate(
            lastActiveOn: '2026-10-01',
            lastSentAt: '2026-09-01 09:00:00',
            reactedAt: null,
            lastSentWithCasesAt: '2026-09-01 09:00:00',
            strong: ['b1'],
            notices: ['n1'],
        ));

        self::assertNotNull($plan);
        self::assertSame(ResultReviewContactType::Weekly, $plan->type);
        self::assertSame(ResultReviewContactPlanner::PRIORITY_WEEKLY, $plan->priority);
        // No new cases for somebody who ignored us - the mark is told anyway
        self::assertSame([], $plan->caseIds);
        self::assertSame(['n1'], $plan->suspiciousNoticeIds);
    }

    public function testLaterVerificationNoticesFollowTheRulesOfRemovals(): void
    {
        $candidate = fn (null|string $lastActiveOn, string $lastSentAt): ResultReviewCandidate => $this->candidate(
            lastActiveOn: $lastActiveOn,
            lastSentAt: $lastSentAt,
            reactedAt: $lastSentAt,
            notices: ['n1'],
        );

        // Only to players active in the last 3 months ...
        self::assertNull($this->plan($candidate('2026-06-30', '2026-08-01 09:00:00')));
        self::assertNull($this->plan($candidate(null, '2026-08-01 09:00:00')));
        // ... at most one e-mail per 7 days
        self::assertNull($this->plan($candidate('2026-10-01', '2026-09-26 20:00:00')));

        $plan = $this->plan($candidate('2026-10-01', '2026-09-25 20:00:00'));
        self::assertNotNull($plan);
        self::assertSame(ResultReviewContactType::Weekly, $plan->type);
        self::assertSame(['n1'], $plan->suspiciousNoticeIds);
    }

    public function testNothingToTellIsNoEmail(): void
    {
        self::assertNull($this->plan($this->candidate(lastActiveOn: '2026-10-01')));
        self::assertNull($this->plan($this->candidate(lastActiveOn: '2026-10-01', lastSentAt: '2026-09-01 09:00:00', reactedAt: '2026-09-01 10:00:00')));
    }

    private function plan(ResultReviewCandidate $candidate): null|PlannedResultReviewContact
    {
        return (new ResultReviewContactPlanner())->plan($candidate, new DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<string> $strong
     * @param list<string> $possible
     * @param list<string> $removals
     * @param list<string> $notices
     */
    private function candidate(
        null|string $lastActiveOn,
        null|string $lastSentAt = null,
        null|string $reactedAt = null,
        null|string $lastSentWithCasesAt = null,
        array $strong = [],
        array $possible = [],
        array $removals = [],
        array $notices = [],
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
            suspiciousNoticeIds: $notices,
        );
    }
}
