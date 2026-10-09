<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Results\PuzzleTag;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
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

    /**
     * A competition's tag carries its name - a tag of drafts only is nowhere until one of them is published
     * (docs/features/organizations/README.md "Drafts"): not on the puzzle page, not in the tag filter, not on list cards
     */
    public function testATagOfADraftEventOnlyIsLeftOutEverywhere(): void
    {
        $tagId = $this->newTag('Birchwood Draft Tag');
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id = :competitionId',
            ['tagId' => $tagId, 'competitionId' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );
        $this->tagPuzzle($tagId, PuzzleFixture::PUZZLE_1000_04);

        $this->assertTagNowhere('Birchwood Draft Tag');

        $this->database->executeStatement(
            'UPDATE competition SET is_draft = false WHERE id = :competitionId',
            ['competitionId' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );

        $tags = $this->tagsByName($this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04));
        self::assertArrayHasKey('Birchwood Draft Tag', $tags);
        self::assertSame(['slug' => OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG], $tags['Birchwood Draft Tag']->competition?->routeParameters());
        self::assertContains('Birchwood Draft Tag', $this->names($this->query->all()));
        self::assertContains('Birchwood Draft Tag', $this->names($this->query->allGroupedPerPuzzle([PuzzleFixture::PUZZLE_1000_04])[PuzzleFixture::PUZZLE_1000_04] ?? []));
    }

    public function testATagOfADraftSeriesOrOfAnEditionOfOneIsLeftOut(): void
    {
        $seriesTag = $this->newTag('Quiet Pines Tag');
        $editionTag = $this->newTag('Quiet Pines Evening Tag');
        $this->database->executeStatement(
            'UPDATE competition_series SET tag_id = :tagId WHERE id = :seriesId',
            ['tagId' => $seriesTag, 'seriesId' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT],
        );
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id = :editionId',
            ['tagId' => $editionTag, 'editionId' => OrganizationFixture::EDITION_QUIET_PINES_1],
        );
        $this->tagPuzzle($seriesTag, PuzzleFixture::PUZZLE_1000_04);
        $this->tagPuzzle($editionTag, PuzzleFixture::PUZZLE_1000_04);

        $this->assertTagNowhere('Quiet Pines Tag');
        $this->assertTagNowhere('Quiet Pines Evening Tag');

        $this->database->executeStatement(
            'UPDATE competition_series SET is_draft = false WHERE id = :seriesId',
            ['seriesId' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT],
        );

        self::assertSame(['Quiet Pines Evening Tag', 'Quiet Pines Tag'], $this->names($this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)));
    }

    /**
     * A tag shared by a published event and a draft carries the published one's name anyway - it stays, linking the
     * published event
     */
    public function testATagSharedWithAPublishedEventStays(): void
    {
        $tagId = $this->newTag('Shared Cup Tag');
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id IN (:draft, :published)',
            ['tagId' => $tagId, 'draft' => OrganizationFixture::COMPETITION_DRAFT_NIGHT, 'published' => CompetitionFixture::COMPETITION_WJPC_2024],
        );
        $this->tagPuzzle($tagId, PuzzleFixture::PUZZLE_1000_04);

        $tags = $this->tagsByName($this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04));

        self::assertArrayHasKey('Shared Cup Tag', $tags);
        self::assertSame(['slug' => 'wjpc-2024'], $tags['Shared Cup Tag']->competition?->routeParameters());
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

    private function newTag(string $name): string
    {
        $tagId = Uuid::uuid7()->toString();
        $this->database->executeStatement('INSERT INTO tag (id, name) VALUES (:id, :name)', ['id' => $tagId, 'name' => $name]);

        return $tagId;
    }

    private function assertTagNowhere(string $name): void
    {
        self::assertNotContains($name, $this->names($this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)));
        self::assertNotContains($name, $this->names($this->query->all()));
        self::assertNotContains($name, $this->names($this->query->allGroupedPerPuzzle([PuzzleFixture::PUZZLE_1000_04])[PuzzleFixture::PUZZLE_1000_04] ?? []));
        self::assertNotContains($name, $this->names(array_merge(...array_values($this->query->allGroupedPerPuzzle()))));
    }

    /**
     * @param array<PuzzleTag> $tags
     * @return list<string>
     */
    private function names(array $tags): array
    {
        $names = array_map(static fn (PuzzleTag $tag): string => $tag->name, array_values($tags));
        sort($names);

        return $names;
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
