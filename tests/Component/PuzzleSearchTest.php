<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\PuzzleSearch;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The puzzle database's "My list" filter: who gets which lists, and what it costs.
 */
final class PuzzleSearchTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    /**
     * @param array<string, mixed> $data
     */
    private function search(KernelBrowser $client, null|string $playerId, array $data = []): TestLiveComponent
    {
        if ($playerId !== null) {
            TestingLogin::asPlayer($client, $playerId);
        }

        $component = $this->createLiveComponent('PuzzleSearch', $data, $client);
        $component->setRouteLocale('en');

        return $component;
    }

    public function testGuestHasNoListFilterAndCannotForceOne(): void
    {
        $client = self::createClient();
        $component = $this->search($client, null, ['list' => 'wishlist']);

        $crawler = new Crawler($component->render()->toString());

        self::assertCount(0, $crawler->filter('#puzzle-search-list'));
        self::assertNull($this->listOf($component));
    }

    public function testNonMemberGetsTheFreeListsOnly(): void
    {
        $client = self::createClient();
        $component = $this->search($client, PlayerFixture::PLAYER_REGULAR, ['list' => 'sell-swap']);

        $crawler = new Crawler($component->render()->toString());

        self::assertSame(['library', 'wishlist', 'unsolved', 'solved'], $this->optionValues($crawler));
        self::assertCount(0, $crawler->filter('#puzzle-search-list optgroup'));
        self::assertNull($this->listOf($component), 'A member list is stripped for a non-member');

        // His own custom collection is a member feature too
        $component = $this->search($client, PlayerFixture::PLAYER_REGULAR, ['list' => 'collection:' . CollectionFixture::COLLECTION_PRIVATE]);
        $component->render();
        self::assertNull($this->listOf($component));
    }

    public function testMemberGetsAllListsAndResultsAreFiltered(): void
    {
        $client = self::createClient();
        $list = 'collection:' . CollectionFixture::COLLECTION_STRIPE_TREFL;
        $component = $this->search($client, PlayerFixture::PLAYER_WITH_STRIPE, ['list' => $list]);

        // Filtered states load on the (deferred) live re-render, like in the browser
        $crawler = new Crawler($component->refresh()->render()->toString());

        self::assertSame([
            'library', 'wishlist', 'unsolved', 'solved',
            'collection:' . CollectionFixture::COLLECTION_STRIPE_TREFL,
            'collection:' . CollectionFixture::COLLECTION_PUBLIC,
            'borrowed', 'lent', 'sell-swap',
        ], $this->optionValues($crawler));
        self::assertSame($list, $crawler->filter('#puzzle-search-list option[selected]')->attr('value'));

        self::assertCount(3, $crawler->filter('[id^="puzzle-list-item-"]'));
        self::assertCount(1, $crawler->filter('#puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_04));
        self::assertCount(0, $crawler->filter('#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01));
    }

    public function testMemberFiltersNotRatedYet(): void
    {
        $client = self::createClient();
        $component = $this->search($client, PlayerFixture::PLAYER_WITH_STRIPE, ['difficultyTiers' => ['0'], 'search' => 'Puzzle']);

        $crawler = new Crawler($component->refresh()->render()->toString());

        self::assertNotNull($crawler->filter('#difficulty-0')->attr('checked'));
        self::assertSame('/difficulty-icons-sprite.svg#diff-unknown', $crawler->filter('label[for="difficulty-0"] use')->attr('href'));
        self::assertCount(1, $crawler->filter('#puzzle-list-item-' . PuzzleFixture::PUZZLE_9000));
    }

    public function testSomebodyElsesCollectionIsDropped(): void
    {
        $client = self::createClient();
        $component = $this->search($client, PlayerFixture::PLAYER_WITH_STRIPE, ['list' => 'collection:' . CollectionFixture::COLLECTION_FAVORITES]);

        $component->render();

        self::assertNull($this->listOf($component));
    }

    public function testSignedInPlayerSeesTheirBestTimeWithoutBeingRanked(): void
    {
        $client = self::createClient();
        $component = $this->search($client, PlayerFixture::PLAYER_REGULAR, ['search' => 'Puzzle']);
        $component->render();

        $this->startCountingQueries($client);
        $crawler = new Crawler($component->refresh()->render()->toString());

        // The same times the cards used to take from the player's ranking
        $ranking = self::getContainer()->get(GetRanking::class)->allForPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $ranking, 'Premise: John solved Puzzle 1');

        $myTime = $crawler->filter('#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01 . ' .puzzle-times-info');
        self::assertCount(1, $myTime->filter('.ci-user'));
        self::assertStringContainsString((new PuzzlingTimeFormatter())->formatTime($ranking[PuzzleFixture::PUZZLE_500_01]->time), $myTime->text());

        self::assertSame([], array_values(array_filter(
            $this->executedSql($client),
            static fn (string $sql): bool => str_contains($sql, 'PlayerPuzzles'),
        )), 'A best time needs no rank against everybody on every puzzle John ever solved');
    }

    public function testListCostsNoExtraQueriesAndGuestsPayNothing(): void
    {
        $client = self::createClient();

        // A search (non-default, so no shared cache) with and without a list, same member
        $withoutList = $this->queriesOf($client, PlayerFixture::PLAYER_WITH_STRIPE, ['search' => 'puzzle']);
        $withList = $this->queriesOf($client, PlayerFixture::PLAYER_WITH_STRIPE, ['search' => 'puzzle', 'list' => 'sell-swap']);
        self::assertCount(count($withoutList), $withList, 'The list is part of the search queries, nothing on top');

        // Result size does not matter: 3 results cost what 7 do
        $narrow = $this->queriesOf($client, PlayerFixture::PLAYER_WITH_STRIPE, ['list' => 'collection:' . CollectionFixture::COLLECTION_STRIPE_TREFL]);
        $wide = $this->queriesOf($client, PlayerFixture::PLAYER_WITH_STRIPE, ['list' => 'sell-swap']);
        self::assertCount(count($wide), $narrow);

        // Guests and non-members never look up collections
        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            self::ensureKernelShutdown();
            $client = self::createClient();
            foreach ($this->queriesOf($client, $viewer, ['search' => 'puzzle', 'list' => 'wishlist']) as $sql) {
                self::assertDoesNotMatchRegularExpression('/FROM collection\s+WHERE/', $sql, 'GetPlayerCollections ran');
            }
        }
    }

    /**
     * SQL of one live re-render (the request that loads a filtered page).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function queriesOf(KernelBrowser $client, null|string $playerId, array $data): array
    {
        $component = $this->search($client, $playerId, $data);
        $component->render();

        $this->startCountingQueries($client);
        $component->refresh();

        return $this->executedSql($client);
    }

    public function testChipFiltersByExactPieceCountAndFillsTheBounds(): void
    {
        $client = self::createClient();
        $component = $this->search($client, null);
        $component->set('pieces', '300');

        $puzzleSearch = $this->puzzleSearchOf($component);
        self::assertSame(300, $puzzleSearch->piecesMin);
        self::assertSame(300, $puzzleSearch->piecesMax);
        self::assertNotSame([], $puzzleSearch->puzzles);

        foreach ($puzzleSearch->puzzles as $puzzle) {
            self::assertSame(300, $puzzle->piecesCount);
        }
    }

    public function testTypedBoundsComposeTheFilter(): void
    {
        $client = self::createClient();
        $component = $this->search($client, null);

        $component->set('piecesMin', 1500);
        $puzzleSearch = $this->puzzleSearchOf($component);
        self::assertSame('1500-', $puzzleSearch->pieces);
        self::assertNotSame([], $puzzleSearch->puzzles);

        foreach ($puzzleSearch->puzzles as $puzzle) {
            self::assertGreaterThanOrEqual(1500, $puzzle->piecesCount);
        }

        // "From" above "to" is put in order rather than matching nothing
        $component->set('piecesMax', 1000);
        $puzzleSearch = $this->puzzleSearchOf($component);
        self::assertSame('1000-1500', $puzzleSearch->pieces);
        self::assertSame(1000, $puzzleSearch->piecesMin);
        self::assertSame(1500, $puzzleSearch->piecesMax);

        $component->set('piecesMin', null);
        $component->set('piecesMax', null);
        self::assertNull($this->puzzleSearchOf($component)->pieces);
    }

    public function testLegacyBucketLinkKeepsFiltering(): void
    {
        $client = self::createClient();
        $component = $this->search($client, null, ['pieces' => '1001+']);
        $component->refresh();

        $puzzleSearch = $this->puzzleSearchOf($component);
        self::assertSame('1001-', $puzzleSearch->pieces);
        self::assertSame(1001, $puzzleSearch->piecesMin);
        self::assertNull($puzzleSearch->piecesMax);

        foreach ($puzzleSearch->puzzles as $puzzle) {
            self::assertGreaterThan(1000, $puzzle->piecesCount);
        }
    }

    public function testATypedTermIsOrderedByBestMatchUntilAnotherSortIsPicked(): void
    {
        $client = self::createClient();
        $component = $this->search($client, null, ['search' => 'puzzle 1']);
        $crawler = new Crawler($component->refresh()->render()->toString());

        // The whole name "Puzzle 1" before "Puzzle 10".."Puzzle 19", whatever their solves
        self::assertSame('best-match', $this->activeSort($crawler));
        self::assertSame('puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01, $crawler->filter('[id^="puzzle-list-item-"]')->first()->attr('id'));
        self::assertNull($this->puzzleSearchOf($component)->sortBy, 'Nothing picked - the URL carries no sort');

        $component->call('changeSortBy', ['sort' => 'a-z']);
        self::assertSame('a-z', $this->activeSort(new Crawler($component->render()->toString())));

        // The pick stays when the term goes, and "Best match" is no longer offered
        $component->set('search', '');
        $crawler = new Crawler($component->render()->toString());
        self::assertSame('a-z', $this->activeSort($crawler));
        self::assertCount(0, $crawler->filter('[data-live-sort-param="best-match"]'));
        self::assertSame('a-z', $this->puzzleSearchOf($component)->sortBy);
    }

    public function testBrowsingIsMostSolvedFirstWithoutBestMatch(): void
    {
        $client = self::createClient();
        $component = $this->search($client, null, ['sortBy' => 'best-match']);
        $crawler = new Crawler($component->render()->toString());

        self::assertSame('most-solved', $this->activeSort($crawler));
        self::assertCount(0, $crawler->filter('[data-live-sort-param="best-match"]'));
        self::assertNull($this->puzzleSearchOf($component)->sortBy);
    }

    private function activeSort(Crawler $crawler): null|string
    {
        return $crawler->filter('[data-live-action-param="changeSortBy"].active')->attr('data-live-sort-param');
    }

    /**
     * The instance rebuilt from the props of the last response, with its puzzles loaded as a render would
     */
    private function puzzleSearchOf(TestLiveComponent $component): PuzzleSearch
    {
        $puzzleSearch = $component->component();
        self::assertInstanceOf(PuzzleSearch::class, $puzzleSearch);
        $puzzleSearch->loadData();

        return $puzzleSearch;
    }

    private function listOf(TestLiveComponent $component): null|string
    {
        $puzzleSearch = $component->component();
        self::assertInstanceOf(PuzzleSearch::class, $puzzleSearch);

        return $puzzleSearch->list;
    }

    /**
     * @return list<string>
     */
    private function optionValues(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('#puzzle-search-list option')->each(static fn (Crawler $option): string => (string) $option->attr('value')),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
