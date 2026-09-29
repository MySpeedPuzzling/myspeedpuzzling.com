<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Results\PuzzleTag;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The puzzle page links a tag badge to the competition the tag belongs to (not to a ?tag= filter URL).
 */
final class GetTagsTest extends KernelTestCase
{
    private GetTags $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->query = self::getContainer()->get(GetTags::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTagOfAPubliclyVisibleCompetitionCarriesTheCompetition(): void
    {
        $this->tagPuzzle(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_1000_04);
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_1000_04);

        $tags = $this->tagsByName($this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04));

        self::assertSame(['Online Competition', 'WJPC'], array_keys($tags));

        $competition = $tags['WJPC']->competition;
        self::assertNotNull($competition);
        self::assertSame('WJPC 2024', $competition->name);
        self::assertSame('event_detail', $competition->routeName());
        self::assertSame(['slug' => 'wjpc-2024'], $competition->routeParameters());

        // No competition behind the tag: a plain badge
        self::assertNull($tags['Online Competition']->competition);
    }

    public function testTagOfAnUnapprovedCompetitionCarriesNoCompetition(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id = :competitionId',
            ['tagId' => TagFixture::TAG_ONLINE, 'competitionId' => CompetitionFixture::COMPETITION_UNAPPROVED],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_1000_04);

        $tags = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04);

        self::assertCount(1, $tags);
        self::assertNull($tags[0]->competition);
    }

    public function testTagOfAnEditionLinksTheEditionPage(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id = :editionId',
            ['tagId' => TagFixture::TAG_ONLINE, 'editionId' => CompetitionSeriesFixture::EDITION_EJJ_68],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_1000_04);

        $competition = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)[0]->competition;

        self::assertNotNull($competition);
        self::assertSame('edition_detail', $competition->routeName());
        self::assertSame(
            ['seriesSlug' => 'euro-jigsaw-jam-series', 'editionSlug' => 'ejj-68-february-2026'],
            $competition->routeParameters(),
        );
    }

    public function testTagOfASeriesLinksTheSeriesPage(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_series SET tag_id = :tagId WHERE id = :seriesId',
            ['tagId' => TagFixture::TAG_ONLINE, 'seriesId' => CompetitionSeriesFixture::SERIES_OFFLINE],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_1000_04);

        $competition = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)[0]->competition;

        self::assertNotNull($competition);
        self::assertTrue($competition->isSeries);
        self::assertSame('competition_series_detail', $competition->routeName());
        self::assertSame(['slug' => 'puzzle-meetup-prague'], $competition->routeParameters());
    }

    public function testTagOfSeveralCompetitionsLinksTheLatestOne(): void
    {
        // Czech Nationals 2024 (in 60 days) is later than WJPC 2024 (in 30 days)
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id = :competitionId',
            ['tagId' => TagFixture::TAG_WJPC, 'competitionId' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        );
        $this->tagPuzzle(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_1000_04);

        $competition = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)[0]->competition;

        self::assertNotNull($competition);
        self::assertSame(['slug' => 'czech-nationals-2024'], $competition->routeParameters());
    }

    public function testPuzzleWithoutTags(): void
    {
        self::assertSame([], $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04));
    }

    public function testInvalidPuzzleIdThrows(): void
    {
        $this->expectException(PuzzleNotFound::class);

        $this->query->forPuzzle('not-a-uuid');
    }

    public function testListCardTagsDoNotLoadCompetitions(): void
    {
        $this->tagPuzzle(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_1000_04);

        $tags = $this->query->allGroupedPerPuzzle([PuzzleFixture::PUZZLE_1000_04]);

        self::assertCount(1, $tags[PuzzleFixture::PUZZLE_1000_04]);
        self::assertSame('WJPC', $tags[PuzzleFixture::PUZZLE_1000_04][0]->name);
        self::assertNull($tags[PuzzleFixture::PUZZLE_1000_04][0]->competition);
    }

    private function tagPuzzle(string $tagId, string $puzzleId): void
    {
        $this->database->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => $tagId, 'puzzleId' => $puzzleId],
        );
    }

    /**
     * @param array<PuzzleTag> $tags
     * @return array<string, PuzzleTag>
     */
    private function tagsByName(array $tags): array
    {
        $byName = [];

        foreach ($tags as $tag) {
            $byName[$tag->name] = $tag;
        }

        return $byName;
    }
}
