<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
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
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
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

    /**
     * review2-b m1: a person in two qualified pairs - the better pair goes, the other is skipped with a reason; nothing
     * fails and nobody ends up twice in a round.
     */
    public function testAPersonInTwoQualifiedPairsGoesOnceWithTheBetterPair(): void
    {
        $pairsB = $this->pairsRoundWithAnnaAndCara();
        $sources = [OfficialResultsFixture::ROUND_PAIRS, $pairsB];

        $plan = $this->advance($sources, [OfficialResultsFixture::ROUND_PAIRS_FINAL]);

        // Both pairs won their round in the same relative time - the organiser's round order decides: Puzzle Sharks first
        self::assertSame(['Puzzle Sharks', 'Edge Hunters'], self::names($plan));
        self::assertSame(['Anna and Cara'], array_map(static fn (array $skip): string => $skip['entry']->displayName(), $plan->skipped));
        self::assertSame('member_already_planned', $plan->skipped[0]['reason']);

        $this->advance($sources, [OfficialResultsFixture::ROUND_PAIRS_FINAL], dryRun: false, planHash: $plan->planHash);

        self::assertSame(1, $this->roundsOf(OfficialResultsFixture::PARTICIPANT_ANNA, [OfficialResultsFixture::ROUND_PAIRS_FINAL]));
    }

    public function testAPersonInTwoQualifiedPairsNeverLandsInTwoParallelRounds(): void
    {
        $pairsB = $this->pairsRoundWithAnnaAndCara();
        $semifinalOne = $this->newRound('Pairs Semifinal 1', RoundCategory::Duo);
        $semifinalTwo = $this->newRound('Pairs Semifinal 2', RoundCategory::Duo);
        $sources = [OfficialResultsFixture::ROUND_PAIRS, $pairsB];

        $plan = $this->advance($sources, [$semifinalOne, $semifinalTwo], AdvanceDistribution::Balanced);
        self::assertSame(['member_already_planned'], array_column($plan->skipped, 'reason'));

        $this->advance($sources, [$semifinalOne, $semifinalTwo], AdvanceDistribution::Balanced, dryRun: false, planHash: $plan->planHash);

        self::assertSame(1, $this->roundsOf(OfficialResultsFixture::PARTICIPANT_ANNA, [$semifinalOne, $semifinalTwo]));
        self::assertSame(0, $this->roundsOf(OfficialResultsFixture::PARTICIPANT_CARA, [$semifinalOne, $semifinalTwo]));
    }

    /**
     * review2-business F2: "best of each country" over all the source rounds by the advancement seed. Marked: Anna (cz),
     * Ben (de), Gina (us), Hugo (de) - Slovakia has nobody, so Ivan (sk, Group B 3rd) is taken and marked qualified.
     */
    public function testBestOfEachCountryTakesTheBestOfEveryCountryOverAllSourcesAndMarksThem(): void
    {
        $sources = [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B];

        $plan = $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL], countryRule: 1);

        self::assertSame(['Gina Quick', 'Hugo Slow', 'Ben Steady', 'Ivan Last'], self::names($plan));
        self::assertSame([false, false, false, true], array_map(static fn (AdvancementAssignment $assignment): bool => $assignment->byCountryRule, $plan->assignments));
        self::assertSame([['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN, 'sourceRoundId' => OfficialResultsFixture::ROUND_GROUP_B]], $plan->markedByCountryRule);
        self::assertSame([], $plan->withoutCountry);
        // A dry run marks nobody
        self::assertNull($this->qualifiedAt(OfficialResultsFixture::ENTRY_B_IVAN));

        // Without the rule the plan is another one - its hash does not apply the rule's plan
        self::assertNotSame($this->advance($sources, [OfficialResultsFixture::ROUND_FINAL])->planHash, $plan->planHash);

        $applied = $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL], dryRun: false, planHash: $plan->planHash, countryRule: 1);

        self::assertTrue($applied->applied);
        self::assertNotNull($this->qualifiedAt(OfficialResultsFixture::ENTRY_B_IVAN));
        self::assertSame(5, $this->entriesIn(OfficialResultsFixture::ROUND_FINAL));

        // Applied: the country has its best in - the rule takes nobody more
        self::assertSame([], $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL], countryRule: 1)->markedByCountryRule);
    }

    public function testBestTwoOfEachCountryCountsTheMarkedAndLeavesEntriesWithoutACountryToTheOrganiser(): void
    {
        $this->database->executeStatement('UPDATE competition_participant SET country = NULL WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_IVAN]);

        $plan = $this->advance([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL], countryRule: 2);

        // cz: Anna (marked) + Dan (unfinished, ranked); us: Gina (marked) + Cara; de: Hugo + Ben (both marked)
        $marked = array_column($plan->markedByCountryRule, 'entry');
        sort($marked);
        self::assertSame(['participant_round:' . OfficialResultsFixture::ENTRY_A_CARA, 'participant_round:' . OfficialResultsFixture::ENTRY_A_DAN], $marked);
        self::assertSame(['Ivan Last'], array_map(static fn (array $without): string => $without['entry']->displayName(), $plan->withoutCountry));
        self::assertNotContains('Ivan Last', self::names($plan));
    }

    public function testBestOfEachCountryNeedsOneToNinetyNine(): void
    {
        try {
            $this->advance([OfficialResultsFixture::ROUND_GROUP_A], [OfficialResultsFixture::ROUND_FINAL], countryRule: 0);
            self::fail('Zero per country is no rule.');
        } catch (InvalidAdvancement $invalid) {
            self::assertSame('invalid_country_rule', $invalid->reason);
        }
    }

    /**
     * review2-b nit: the people of the whole plan are loaded in one statement, not one SELECT per person.
     */
    public function testApplyingLoadsThePeopleOfThePlanAtOnce(): void
    {
        $sources = [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B];
        $plan = $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL]);

        /** @var DebugDataHolder $debugData */
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->advance($sources, [OfficialResultsFixture::ROUND_FINAL], dryRun: false, planHash: $plan->planHash);

        $participantSelects = [];
        foreach ($debugData->getData() as $queries) {
            if (!is_array($queries)) {
                continue;
            }

            foreach ($queries as $query) {
                $sql = is_array($query) && is_string($query['sql'] ?? null) ? $query['sql'] : '';

                if (preg_match('/^SELECT .* FROM competition_participant (c|t)\d+_? /', $sql) === 1) {
                    $participantSelects[] = $sql;
                }
            }
        }

        self::assertCount(1, $participantSelects, implode("\n", $participantSelects));
        self::assertStringContainsString(' IN (', $participantSelects[0]);
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
        null|int $countryRule = null,
    ): AdvancementPlan {
        $envelope = $this->messageBus->dispatch(new AdvanceQualified(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            sourceRoundIds: $sources,
            targetRoundIds: $targets,
            distribution: $distribution,
            targetBySource: $map,
            dryRun: $dryRun,
            planHash: $planHash,
            bestOfEachCountry: $countryRule,
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

    private function qualifiedAt(string $participantRoundId): mixed
    {
        return $this->database->fetchOne('SELECT qualified_at FROM competition_participant_round WHERE id = :id', ['id' => $participantRoundId]);
    }

    /**
     * @param list<string> $roundIds
     */
    private function roundsOf(string $participantId, array $roundIds): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM competition_participant_round WHERE participant_id = :participantId AND round_id IN (:roundIds)',
            ['participantId' => $participantId, 'roundIds' => $roundIds],
            ['roundIds' => ArrayParameterType::STRING],
        );
        assert(is_int($count));

        return $count;
    }

    /**
     * "Pairs B": Anna (also in Puzzle Sharks) with Cara, finished as fast relative to the winner - qualified.
     */
    private function pairsRoundWithAnnaAndCara(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        assert($competition !== null);

        $round = new CompetitionRound(id: Uuid::uuid7(), competition: $competition, name: 'Pairs B', minutesLimit: 60, startsAt: new \DateTimeImmutable('-9 days'), category: RoundCategory::Duo);
        $entityManager->persist($round);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, 'Anna and Cara');
        $team->recordResult(RoundEntryResult::finished(5000), null, new \DateTimeImmutable());
        $team->markQualified(new \DateTimeImmutable());
        $entityManager->persist($team);

        foreach ([OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::PARTICIPANT_CARA] as $participantId) {
            $participant = $entityManager->find(CompetitionParticipant::class, $participantId);
            assert($participant !== null);
            $entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team));
        }

        $entityManager->flush();
        $entityManager->clear();

        return $round->id->toString();
    }

    private function newRound(string $name, RoundCategory $category = RoundCategory::Solo): string
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
            category: $category,
        );
        $entityManager->persist($round);
        $entityManager->flush();

        return $round->id->toString();
    }
}
