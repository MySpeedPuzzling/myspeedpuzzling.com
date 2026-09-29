<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Results\CompetitionPuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetCompetitionPuzzlesTest extends KernelTestCase
{
    private GetCompetitionPuzzles $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCompetitionPuzzles::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testRoundPuzzlesInScheduleOrderThenTaggedOnes(): void
    {
        $this->tag(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_500_03);
        // Tagged and attached to a round at once - listed once, in its round's place
        $this->tag(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_1000_01);

        $puzzles = $this->query->forCompetitions([CompetitionFixture::COMPETITION_WJPC_2024]);

        $ids = self::puzzleIds($puzzles[CompetitionFixture::COMPETITION_WJPC_2024] ?? []);
        self::assertCount(5, $ids);
        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02], array_slice($ids, 0, 2), 'Qualification round first');
        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02], array_slice($ids, 2, 2), 'Final round next');
        self::assertSame(PuzzleFixture::PUZZLE_500_03, $ids[4], 'Tag-only puzzle last');
    }

    public function testCarriesThePublicStatistics(): void
    {
        $statistics = $this->database->fetchAssociative(
            'SELECT solved_times_solo_count, median_time_solo, fastest_time_solo FROM puzzle_statistics WHERE puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_500_01],
        );
        self::assertIsArray($statistics);

        $puzzles = $this->query->forCompetitions([CompetitionFixture::COMPETITION_WJPC_2024]);
        $puzzle = self::find($puzzles[CompetitionFixture::COMPETITION_WJPC_2024] ?? [], PuzzleFixture::PUZZLE_500_01);

        self::assertGreaterThan(0, $puzzle->soloSolvesCount);
        self::assertSame($statistics['solved_times_solo_count'], $puzzle->soloSolvesCount);
        self::assertSame($statistics['median_time_solo'], $puzzle->medianTimeSolo);
        self::assertSame($statistics['fastest_time_solo'], $puzzle->fastestTimeSolo);
        self::assertSame(500, $puzzle->piecesCount);
    }

    public function testSecretPuzzlesDoNotLeak(): void
    {
        $puzzles = $this->query->forCompetitions([CompetitionApiFixture::COMPETITION_API]);
        $competitionPuzzles = $puzzles[CompetitionApiFixture::COMPETITION_API] ?? [];
        $ids = self::puzzleIds($competitionPuzzles);

        // Hidden entirely until its round starts, and embargoed platform-wide
        self::assertNotContains(CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, $ids);
        self::assertNotContains(CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, $ids);

        // Listed without an image until the reveal
        self::assertNull(self::find($competitionPuzzles, CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE)->puzzleImage);
        self::assertNull(self::find($competitionPuzzles, CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN)->puzzleImage);

        // Not hidden, or its round already started
        self::assertSame(CompetitionApiFixture::IMAGE_VISIBLE, self::find($competitionPuzzles, CompetitionApiFixture::PUZZLE_VISIBLE)->puzzleImage);
        self::assertSame(CompetitionApiFixture::IMAGE_PAST, self::find($competitionPuzzles, CompetitionApiFixture::PUZZLE_PAST)->puzzleImage);
    }

    public function testTagDoesNotRevealASecretRoundPuzzle(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id = :id',
            ['tagId' => TagFixture::TAG_ONLINE, 'id' => CompetitionApiFixture::COMPETITION_API],
        );
        $this->tag(TagFixture::TAG_ONLINE, CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY);

        $puzzles = $this->query->forCompetitions([CompetitionApiFixture::COMPETITION_API]);

        self::assertNotContains(CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, self::puzzleIds($puzzles[CompetitionApiFixture::COMPETITION_API] ?? []));
    }

    public function testOnlyPubliclyVisibleCompetitionsAnswer(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET approved_at = NULL WHERE id = :id',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        self::assertSame([], $this->query->forCompetitions([CompetitionFixture::COMPETITION_WJPC_2024]));
        self::assertSame([], $this->query->forCompetitions([CompetitionApiFixture::COMPETITION_API_REJECTED]));
    }

    public function testEditionOfAnApprovedSeriesAnswers(): void
    {
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts) VALUES (:id, :roundId, :puzzleId, false)',
            ['id' => Uuid::uuid7()->toString(), 'roundId' => CompetitionSeriesFixture::ROUND_EJJ_68, 'puzzleId' => PuzzleFixture::PUZZLE_500_03],
        );

        $puzzles = $this->query->forCompetitions([CompetitionSeriesFixture::EDITION_EJJ_68, 'not-a-uuid']);

        self::assertSame([PuzzleFixture::PUZZLE_500_03], self::puzzleIds($puzzles[CompetitionSeriesFixture::EDITION_EJJ_68] ?? []));
    }

    public function testNoCompetitionsNoQuery(): void
    {
        self::assertSame([], $this->query->forCompetitions([]));
        self::assertSame([], $this->query->forCompetitions(['not-a-uuid']));
        self::assertSame([], $this->query->roundPuzzleOverviews('not-a-uuid'));
    }

    public function testRoundPuzzleOverviewsInScheduleOrderEachOnce(): void
    {
        // A puzzle in two rounds is listed once, at its first round
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts) VALUES (:id, :roundId, :puzzleId, false)',
            ['id' => Uuid::uuid7()->toString(), 'roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        $ids = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $this->query->roundPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024),
        );

        self::assertCount(4, $ids);
        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02], array_slice($ids, 0, 2), 'Qualification round first');
        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02], array_slice($ids, 2, 2));
    }

    public function testRoundPuzzleOverviewsKeepSecretPuzzlesSecret(): void
    {
        $overviews = [];
        foreach ($this->query->roundPuzzleOverviews(CompetitionApiFixture::COMPETITION_API) as $puzzle) {
            $overviews[$puzzle->puzzleId] = $puzzle;
        }

        self::assertArrayNotHasKey(CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, $overviews);
        self::assertArrayNotHasKey(CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, $overviews);
        self::assertArrayHasKey(CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE, $overviews);
        self::assertNull($overviews[CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE]->puzzleImage);
        self::assertArrayHasKey(CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN, $overviews);
        self::assertNull($overviews[CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN]->puzzleImage);
        self::assertArrayHasKey(CompetitionApiFixture::PUZZLE_VISIBLE, $overviews);
        self::assertSame(CompetitionApiFixture::IMAGE_VISIBLE, $overviews[CompetitionApiFixture::PUZZLE_VISIBLE]->puzzleImage);
        // The past round comes first in the schedule
        self::assertSame(CompetitionApiFixture::PUZZLE_PAST, array_key_first($overviews));
    }

    private function tag(string $tagId, string $puzzleId): void
    {
        $this->database->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => $tagId, 'puzzleId' => $puzzleId],
        );
    }

    /**
     * @param list<CompetitionPuzzle> $puzzles
     * @return list<string>
     */
    private static function puzzleIds(array $puzzles): array
    {
        return array_map(static fn (CompetitionPuzzle $puzzle): string => $puzzle->puzzleId, $puzzles);
    }

    /**
     * @param list<CompetitionPuzzle> $puzzles
     */
    private static function find(array $puzzles, string $puzzleId): CompetitionPuzzle
    {
        foreach ($puzzles as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return $puzzle;
            }
        }

        self::fail(sprintf('Puzzle %s is not listed', $puzzleId));
    }
}
