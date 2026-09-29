<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\PuzzleSearch;
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

    public function testSomebodyElsesCollectionIsDropped(): void
    {
        $client = self::createClient();
        $component = $this->search($client, PlayerFixture::PLAYER_WITH_STRIPE, ['list' => 'collection:' . CollectionFixture::COLLECTION_FAVORITES]);

        $component->render();

        self::assertNull($this->listOf($component));
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
