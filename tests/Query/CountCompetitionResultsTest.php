<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CountCompetitionResultsTest extends KernelTestCase
{
    private CountCompetitionResults $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(CountCompetitionResults::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testCountsTheTimesLinkedToTheCompetition(): void
    {
        // Qualification TIME_09-11 and final TIME_19-20
        self::assertSame(5, $this->query->forCompetition(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertSame(0, $this->query->forCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));
    }

    public function testSuspiciousTimesDoNotCount(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_09],
        );

        self::assertSame(4, $this->query->forCompetition(CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testInvalidIdCountsNothing(): void
    {
        self::assertSame(0, $this->query->forCompetition('not-a-uuid'));
        self::assertSame([], $this->query->perRound('not-a-uuid'));
    }

    public function testCountsPerRound(): void
    {
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION => 3, CompetitionRoundFixture::ROUND_WJPC_FINAL => 2],
            self::sorted($this->query->perRound(CompetitionFixture::COMPETITION_WJPC_2024)),
        );
    }

    public function testRoundsWithoutResultsAreLeftOut(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE competition_round_id = :roundId',
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );

        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION => 3],
            $this->query->perRound(CompetitionFixture::COMPETITION_WJPC_2024),
        );
    }

    /**
     * Results Cup (OfficialResultsFixture): Group A published with 4 ranked entries (did not start and no result do not
     * count), Group B (3 ranked) and Pairs (3 ranked) not published - no player times anywhere.
     */
    public function testPublishedOfficialResultsCountWhenAsked(): void
    {
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;

        self::assertSame(0, $this->query->forCompetition($cup));
        self::assertSame([], $this->query->perRound($cup));
        self::assertSame(4, $this->query->forCompetition($cup, withOfficialResults: true));
        self::assertSame([OfficialResultsFixture::ROUND_GROUP_A => 4], $this->query->perRound($cup, withOfficialResults: true));

        $this->database->executeStatement(
            'UPDATE competition_round SET results_published_at = NOW() WHERE id IN (:pairs, :final)',
            ['pairs' => OfficialResultsFixture::ROUND_PAIRS, 'final' => OfficialResultsFixture::ROUND_FINAL],
        );

        // The Final has nobody ranked yet - left out like a round without results
        self::assertSame(7, $this->query->forCompetition($cup, withOfficialResults: true));
        self::assertSame(
            self::sorted([OfficialResultsFixture::ROUND_GROUP_A => 4, OfficialResultsFixture::ROUND_PAIRS => 3]),
            self::sorted($this->query->perRound($cup, withOfficialResults: true)),
        );
    }

    public function testPlayerTimesAndOfficialResultsAddUp(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_round SET results_published_at = NOW() WHERE id = :id',
            ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );
        $participantId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, source) VALUES (:id, 'Olga Official', :competitionId, 'imported')",
            ['id' => $participantId, 'competitionId' => CompetitionFixture::COMPETITION_WJPC_2024],
        );
        $this->database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, result_pieces_placed, result_did_not_start) VALUES (:id, :participantId, :roundId, 300, false)',
            ['id' => Uuid::uuid7()->toString(), 'participantId' => $participantId, 'roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );

        self::assertSame(6, $this->query->forCompetition(CompetitionFixture::COMPETITION_WJPC_2024, withOfficialResults: true));
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION => 3, CompetitionRoundFixture::ROUND_WJPC_FINAL => 3],
            self::sorted($this->query->perRound(CompetitionFixture::COMPETITION_WJPC_2024, withOfficialResults: true)),
        );
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
