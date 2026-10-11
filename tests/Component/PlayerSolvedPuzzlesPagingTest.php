<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\PlayerSolvedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The profile renders the first PAGE_SIZE rows of the shown tab, "Show more" adds the next ones, and another list (tab,
 * filter, sort) starts from its top again. Filters, sorts and the tab counts still cover the whole history.
 */
final class PlayerSolvedPuzzlesPagingTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    // PLAYER_REGULAR has 9 solo puzzles in the fixtures; 60 more give two pages and a bit
    private const int EXTRA_PUZZLES = 60;

    public function testFirstRowsThenShowMore(): void
    {
        $component = $this->results();
        $results = $this->resultsOf($component);
        $total = $results->getRowsCount();

        self::assertGreaterThan(PlayerSolvedPuzzles::PAGE_SIZE, $total);
        self::assertCount(PlayerSolvedPuzzles::PAGE_SIZE, $results->getShownRows());
        self::assertCount($total, $results->soloSolvedPuzzles, 'Filters and the tab count cover the whole history');
        self::assertSame(min(PlayerSolvedPuzzles::PAGE_SIZE, $total - PlayerSolvedPuzzles::PAGE_SIZE), $results->getShowMoreCount());

        $html = $component->render()->toString();
        self::assertSame(PlayerSolvedPuzzles::PAGE_SIZE, substr_count($html, 'data-controller="row-link"'));
        self::assertStringContainsString('Showing 50 of ' . $total, $html);
        self::assertStringContainsString('(' . $total . ')', $html, 'The solo tab counts every puzzle');
        self::assertStringContainsString('data-live-action-param="showMore"', $html);

        // Ranks are looked up for the rows on screen only - every shown timed solo row has one
        $shownPuzzleIds = array_map(static fn (array $group): string => $group[0]->puzzleId, array_values($results->getShownRows()));
        self::assertSame([], array_diff(array_keys($results->ranking), $shownPuzzleIds));
        self::assertNotSame([], $results->ranking);

        $component->call('showMore');
        $results = $this->resultsOf($component);
        self::assertCount(min(2 * PlayerSolvedPuzzles::PAGE_SIZE, $total), $results->getShownRows());
        self::assertGreaterThan(PlayerSolvedPuzzles::PAGE_SIZE, count($results->ranking), 'The added rows have their ranks too');

        $html = $component->render()->toString();
        self::assertSame($total, substr_count($html, 'data-controller="row-link"'));
        self::assertStringNotContainsString('data-live-action-param="showMore"', $html, 'Everything shown, no button');
    }

    public function testShownRowsBelongToTheirList(): void
    {
        $component = $this->results();
        $component->call('showMore');
        self::assertSame(2 * PlayerSolvedPuzzles::PAGE_SIZE, $this->resultsOf($component)->limit);

        // The brand select sends "" for "All brands" while the prop is null - the same list, the rows stay
        $component->set('manufacturer', '');
        self::assertSame(2 * PlayerSolvedPuzzles::PAGE_SIZE, $this->resultsOf($component)->limit);

        // Another sort is another list
        $component->call('changeSortBy', ['sort' => 'newest']);
        self::assertSame(PlayerSolvedPuzzles::PAGE_SIZE, $this->resultsOf($component)->limit);

        $component->call('showMore');
        self::assertSame(2 * PlayerSolvedPuzzles::PAGE_SIZE, $this->resultsOf($component)->limit);

        // A filter too
        $component->set('searchQuery', 'paging');
        self::assertSame(PlayerSolvedPuzzles::PAGE_SIZE, $this->resultsOf($component)->limit);

        $component->set('searchQuery', null);
        $component->call('showMore');
        self::assertSame(2 * PlayerSolvedPuzzles::PAGE_SIZE, $this->resultsOf($component)->limit);

        // And another tab
        $component->call('changeResultsCategory', ['category' => 'duo']);
        self::assertSame(PlayerSolvedPuzzles::PAGE_SIZE, $this->resultsOf($component)->limit);
    }

    public function testAFilterNarrowsTheWholeHistoryNotTheShownRows(): void
    {
        $component = $this->results();

        // Every copied puzzle is named "… paging N": the search finds all of them, also those beyond the first rows
        $component->set('searchQuery', 'paging');
        $results = $this->resultsOf($component);

        self::assertSame(self::EXTRA_PUZZLES, $results->getRowsCount());
        self::assertCount(PlayerSolvedPuzzles::PAGE_SIZE, $results->getShownRows());
    }

    private function results(): TestLiveComponent
    {
        $client = self::createClient();
        $this->addPuzzlesWithSoloTimes(PlayerFixture::PLAYER_REGULAR);

        $component = $this->createLiveComponent('PlayerSolvedPuzzles', ['playerId' => PlayerFixture::PLAYER_REGULAR], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * The instance rebuilt from the props of the last response, with its results loaded as a render would
     */
    private function resultsOf(TestLiveComponent $component): PlayerSolvedPuzzles
    {
        $results = $component->component();
        self::assertInstanceOf(PlayerSolvedPuzzles::class, $results);
        $results->populate();

        return $results;
    }

    /**
     * Copies of one of the player's puzzles and of their solo time on it (rolled back with the test's transaction)
     */
    private function addPuzzlesWithSoloTimes(string $playerId): void
    {
        $database = self::getContainer()->get(Connection::class);

        /** @var array{id: string, puzzle_id: string} $time */
        $time = $database->fetchAssociative(
            "SELECT id, puzzle_id FROM puzzle_solving_time WHERE player_id = :playerId AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL ORDER BY id LIMIT 1",
            ['playerId' => $playerId],
        );

        $database->executeStatement(
            'CREATE TEMP TABLE paging_puzzle ON COMMIT DROP AS SELECT puzzle.*, g AS copy FROM puzzle, generate_series(1, :copies) g WHERE puzzle.id = :puzzleId',
            ['copies' => self::EXTRA_PUZZLES, 'puzzleId' => $time['puzzle_id']],
        );
        $database->executeStatement("UPDATE paging_puzzle SET id = gen_random_uuid(), name = name || ' paging ' || copy, search_names = search_names || E'\\n' || 'paging ' || copy");
        $database->executeStatement(
            'CREATE TEMP TABLE paging_time ON COMMIT DROP AS SELECT pst.*, pp.id AS copy_puzzle_id FROM puzzle_solving_time pst, paging_puzzle pp WHERE pst.id = :timeId',
            ['timeId' => $time['id']],
        );
        $database->executeStatement('UPDATE paging_time SET id = gen_random_uuid(), puzzle_id = copy_puzzle_id');
        $database->executeStatement('ALTER TABLE paging_puzzle DROP COLUMN copy');
        $database->executeStatement('ALTER TABLE paging_time DROP COLUMN copy_puzzle_id');
        $database->executeStatement('INSERT INTO puzzle SELECT * FROM paging_puzzle');
        $database->executeStatement('INSERT INTO puzzle_solving_time SELECT * FROM paging_time');
    }
}
