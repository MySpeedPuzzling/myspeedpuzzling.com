<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\AdvancementPlanChanged;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancement;
use SpeedPuzzling\Web\Message\AdvanceQualified;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Results\AdvancementAssignment;
use SpeedPuzzling\Web\Results\AdvancementPlan;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class AdvanceQualifiedHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheDryRunPlansTheQualifiedBySeedAndSkipsWhoIsThereAlready(): void
    {
        $plan = $this->advance([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL]);

        self::assertFalse($plan->applied);
        // Seeds: Anna (A winner), Gina (B winner), Hugo (B 2nd, 4500/3900 = 1.15), Ben (A 2nd, 4200/3600 = 1.17) - Anna is
        // in the final already
        self::assertSame(['Gina Quick', 'Hugo Slow', 'Ben Steady'], self::names($plan));
        self::assertSame([2, 3, 4], array_map(static fn (AdvancementAssignment $assignment): int => $assignment->seed, $plan->assignments));
        self::assertCount(1, $plan->skipped);
        self::assertSame('Anna Fast', $plan->skipped[0]['entry']->name);
        self::assertSame('already_in_target', $plan->skipped[0]['reason']);
        self::assertSame([['roundId' => OfficialResultsFixture::ROUND_FINAL, 'name' => 'Final', 'entriesBefore' => 1, 'entriesAfter' => 4]], $plan->targets);
        self::assertSame(1, $this->entriesIn(OfficialResultsFixture::ROUND_FINAL));
    }

    public function testApplyingWritesExactlyThePlanAndAdvancingTwiceAddsNobody(): void
    {
        $sources = [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B];
        $plan = $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL]);

        $applied = $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL], dryRun: false, planHash: $plan->planHash);

        self::assertTrue($applied->applied);
        self::assertSame($plan->planHash, $applied->planHash);
        self::assertSame(4, $this->entriesIn(OfficialResultsFixture::ROUND_FINAL));
        foreach ($applied->assignments as $assignment) {
            self::assertNotNull($assignment->createdEntryRef);
        }

        $again = $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL]);
        self::assertSame([], $again->assignments);
        self::assertCount(4, $again->skipped);
    }

    public function testApplyingAnOutdatedPlanWritesNothing(): void
    {
        $sources = [OfficialResultsFixture::ROUND_GROUP_A];
        $plan = $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL]);

        // Somebody marks Cara qualified after the organiser saw the plan
        $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse([[
                'clientChangeId' => Uuid::uuid7()->toString(),
                'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_CARA,
                'field' => 'qualified',
                'from' => false,
                'to' => true,
            ]]),
        ));

        try {
            $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL], dryRun: false, planHash: $plan->planHash);
            self::fail('An outdated plan must be refused.');
        } catch (AdvancementPlanChanged) {
        }

        self::assertSame(1, $this->entriesIn(OfficialResultsFixture::ROUND_FINAL));
    }

    public function testApplyingWithoutAPlanIsRefused(): void
    {
        $this->expectException(AdvancementPlanChanged::class);

        $this->advance([OfficialResultsFixture::ROUND_GROUP_A], [OfficialResultsFixture::ROUND_FINAL], dryRun: false);
    }

    public function testBalancedServesTheTargetsInASerpentine(): void
    {
        $semifinalOne = $this->newRound('Semifinal 1');
        $semifinalTwo = $this->newRound('Semifinal 2');

        // Cara qualifies too - tied with Ben: seeds Anna 1, Gina 2, Hugo 3, Ben 4, Cara 5
        $this->database->executeStatement('UPDATE competition_participant_round SET qualified_at = NOW() WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_CARA]);

        $plan = $this->advance(
            [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B],
            [$semifinalOne, $semifinalTwo],
            AdvanceDistribution::Balanced,
        );

        self::assertSame(
            ['Anna Fast' => $semifinalOne, 'Gina Quick' => $semifinalTwo, 'Hugo Slow' => $semifinalTwo, 'Ben Steady' => $semifinalOne, 'Cara Tied' => $semifinalOne],
            array_combine(self::names($plan), array_map(static fn (AdvancementAssignment $assignment): string => $assignment->targetRoundId, $plan->assignments)),
        );
    }

    public function testBySourceFollowsTheMap(): void
    {
        $semifinalOne = $this->newRound('Semifinal 1');
        $semifinalTwo = $this->newRound('Semifinal 2');

        $plan = $this->advance(
            [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B],
            [$semifinalOne, $semifinalTwo],
            AdvanceDistribution::BySource,
            [OfficialResultsFixture::ROUND_GROUP_A => $semifinalTwo, OfficialResultsFixture::ROUND_GROUP_B => $semifinalOne],
        );

        self::assertSame(
            ['Anna Fast' => $semifinalTwo, 'Gina Quick' => $semifinalOne, 'Hugo Slow' => $semifinalOne, 'Ben Steady' => $semifinalTwo],
            array_combine(self::names($plan), array_map(static fn (AdvancementAssignment $assignment): string => $assignment->targetRoundId, $plan->assignments)),
        );
    }

    public function testPairsAdvanceAsTheSamePeopleUnderTheSameName(): void
    {
        $plan = $this->advance([OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL]);
        self::assertSame(['Puzzle Sharks', 'Edge Hunters'], self::names($plan));

        $this->advance([OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL], dryRun: false, planHash: $plan->planHash);

        $teams = $this->database->fetchAllAssociative(
            <<<SQL
SELECT ct.name, STRING_AGG(cp.name, ', ' ORDER BY cp.name) AS members, ct.result_seconds, ct.qualified_at
FROM competition_team ct
INNER JOIN competition_participant_round cpr ON cpr.team_id = ct.id AND cpr.round_id = ct.round_id
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
WHERE ct.round_id = :roundId
GROUP BY ct.id
ORDER BY ct.name
SQL,
            ['roundId' => OfficialResultsFixture::ROUND_PAIRS_FINAL],
        );

        self::assertSame([
            ['name' => 'Edge Hunters', 'members' => 'Gina Quick, Hugo Slow', 'result_seconds' => null, 'qualified_at' => null],
            ['name' => 'Puzzle Sharks', 'members' => 'Anna Fast, Ben Steady', 'result_seconds' => null, 'qualified_at' => null],
        ], $teams);

        $again = $this->advance([OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL]);
        self::assertSame([], $again->assignments);
        self::assertSame(['already_in_target', 'already_in_target'], array_column($again->skipped, 'reason'));
    }

    public function testRoundsOfDifferentKindsAreRefused(): void
    {
        $this->expectException(InvalidAdvancement::class);

        $this->advance([OfficialResultsFixture::ROUND_GROUP_A], [OfficialResultsFixture::ROUND_PAIRS_FINAL]);
    }

    public function testSingleNeedsOneTarget(): void
    {
        try {
            $this->advance([OfficialResultsFixture::ROUND_GROUP_A], [OfficialResultsFixture::ROUND_GROUP_B, OfficialResultsFixture::ROUND_FINAL]);
            self::fail('Two targets are not "single".');
        } catch (InvalidAdvancement $invalid) {
            self::assertSame('single_needs_one_target', $invalid->reason);
        }
    }

    public function testARoundIsNeverSourceAndTarget(): void
    {
        try {
            $this->advance([OfficialResultsFixture::ROUND_GROUP_A], [OfficialResultsFixture::ROUND_GROUP_A]);
            self::fail('Source and target must differ.');
        } catch (InvalidAdvancement $invalid) {
            self::assertSame('round_both_source_and_target', $invalid->reason);
        }
    }

    public function testRoundsOfAnotherCompetitionAreRefused(): void
    {
        $this->expectException(CompetitionRoundNotFound::class);

        $this->advance([OfficialResultsFixture::ROUND_GROUP_A], [CompetitionRoundFixture::ROUND_WJPC_FINAL]);
    }

    /**
     * @param list<string> $sources
     * @param list<string> $targets
     * @param array<string, string> $map
     */
    private function advance(
        array $sources,
        array $targets,
        AdvanceDistribution $distribution = AdvanceDistribution::Single,
        array $map = [],
        bool $dryRun = true,
        null|string $planHash = null,
    ): AdvancementPlan {
        $envelope = $this->messageBus->dispatch(new AdvanceQualified(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            sourceRoundIds: $sources,
            targetRoundIds: $targets,
            distribution: $distribution,
            targetBySource: $map,
            dryRun: $dryRun,
            planHash: $planHash,
        ));

        $plan = $envelope->last(HandledStamp::class)?->getResult();
        assert($plan instanceof AdvancementPlan);

        return $plan;
    }

    /**
     * @return list<string>
     */
    private static function names(AdvancementPlan $plan): array
    {
        return array_map(static fn (AdvancementAssignment $assignment): string => $assignment->entry->displayName(), $plan->assignments);
    }

    private function entriesIn(string $roundId): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id', ['id' => $roundId]);
        assert(is_int($count));

        return $count;
    }

    private function newRound(string $name): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        assert($competition !== null);

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: $name,
            minutesLimit: 60,
            startsAt: new \DateTimeImmutable('-9 days'),
            category: RoundCategory::Solo,
        );
        $entityManager->persist($round);
        $entityManager->flush();

        return $round->id->toString();
    }
}
