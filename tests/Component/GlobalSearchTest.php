<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\GlobalSearch;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The header search: the best 15 puzzles by best match, then "Show all N results" into the catalogue - counted by a
 * query only when the 15 may not be all of them.
 */
final class GlobalSearchTest extends WebTestCase
{
    use ChangesPuzzleRecords;
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    public function testTheBestFifteenAndALinkToAllOfThem(): void
    {
        $client = self::createClient();
        // Unsolved, but the only whole name - the most solved "Puzzle 1".."Puzzle 20" only start with it
        self::renamePuzzle(PuzzleFixture::PUZZLE_9000, 'Puzzle');
        $component = $this->globalSearch($client, 'puzzle');
        $component->render();

        $this->startCountingQueries($client);
        $crawler = new Crawler($component->refresh()->render()->toString());

        $total = self::getContainer()->get(SearchPuzzle::class)->countByUserInput(null, 'puzzle', PiecesRange::any(), null);
        self::assertGreaterThan(GlobalSearch::PUZZLES_LIMIT, $total, 'Premise: more puzzles match than are shown');

        $names = $crawler->filter('.global-search-results .puzzle-name a');
        self::assertCount(GlobalSearch::PUZZLES_LIMIT, $names);
        self::assertStringEndsWith('/' . PuzzleFixture::PUZZLE_9000, (string) $names->first()->attr('href'));

        $link = $crawler->filter('.global-search-results a[href^="/en/puzzle?"]');
        self::assertCount(1, $link);
        self::assertSame('/en/puzzle?search=puzzle', $link->attr('href'));
        self::assertSame(sprintf('Show all %d results', $total), trim($link->text()));
        self::assertCount(1, $this->countQueries($client));
    }

    public function testFewerThanFifteenAreCountedWithoutAQuery(): void
    {
        $client = self::createClient();
        $component = $this->globalSearch($client, 'Kouzelná');
        $component->render();

        $this->startCountingQueries($client);
        $crawler = new Crawler($component->refresh()->render()->toString());

        // PUZZLE_1000_02 and PUZZLE_300 carry the name
        self::assertCount(2, $crawler->filter('.global-search-results .puzzle-name a'));
        self::assertSame('Show all 2 results', trim($crawler->filter('.global-search-results a[href^="/en/puzzle?"]')->text()));
        self::assertCount(0, $this->countQueries($client));
    }

    public function testNoResultNoLink(): void
    {
        $client = self::createClient();

        // Invisible characters fold to nothing - no text filter would list the whole catalogue
        foreach (['xyzqw', "\u{200B}"] as $query) {
            $crawler = new Crawler($this->globalSearch($client, $query)->render()->toString());

            self::assertCount(0, $crawler->filter('.global-search-results .puzzle-name'), $query);
            self::assertCount(0, $crawler->filter('.global-search-results a[href^="/en/puzzle?"]'), $query);
        }
    }

    private function globalSearch(KernelBrowser $client, string $query): TestLiveComponent
    {
        $component = $this->createLiveComponent('GlobalSearch', ['query' => $query], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * @return list<string>
     */
    private function countQueries(KernelBrowser $client): array
    {
        return array_values(array_filter(
            $this->executedSql($client),
            static fn (string $sql): bool => str_contains($sql, 'COUNT(DISTINCT puzzle.id)'),
        ));
    }
}
