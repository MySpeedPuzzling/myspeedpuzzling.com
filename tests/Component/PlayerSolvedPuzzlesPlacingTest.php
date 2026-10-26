<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\PlayerSolvedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Placing sorts, the placing chips (free) and the prediction sorts (members, not on an opted-out profile) on the
 * profile results - feature request #292
 */
final class PlayerSolvedPuzzlesPlacingTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testPlacingSortsOrderTheRowsByRankForEverybody(): void
    {
        $component = $this->results(self::createClient());

        $component->call('changeSortBy', ['sort' => 'best_rank']);
        $ranks = $this->ranks($component->render()->crawler());
        self::assertGreaterThan(1, count($ranks));
        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks);
        self::assertSame('best_rank', $this->component($component)->sortBy);

        $component->call('changeSortBy', ['sort' => 'worst_rank']);
        $ranks = $this->ranks($component->render()->crawler());
        rsort($sorted);
        self::assertSame($sorted, $ranks);
    }

    public function testThePlacingChipNarrowsTheListAndCountsAsAFilter(): void
    {
        $component = $this->results(self::createClient());
        $all = $this->ranks($component->render()->crawler());
        self::assertContains(1, $all);
        self::assertNotSame([], array_filter($all, static fn (int $rank): bool => $rank > 3), 'Some puzzles are placed below 3rd');

        $component->set('placing', 'top3');
        $crawler = $component->render()->crawler();
        $ranks = $this->ranks($crawler);

        self::assertNotSame([], $ranks);
        self::assertSame([], array_filter($ranks, static fn (int $rank): bool => $rank > 3));
        self::assertSame('1', $crawler->filter('#filtersDropdown sup')->text());
        self::assertCount(1, $crawler->filter('input[data-model="placing"][value="top3"][checked]'));

        $component->call('resetFilters');
        self::assertCount(count($all), $this->ranks($component->render()->crawler()));
    }

    public function testAnUnknownPlacingOrSortIsDropped(): void
    {
        $component = $this->results(self::createClient());
        $component->set('placing', 'top1000');
        $component->set('sortBy', 'cheapest');
        $component->render();

        self::assertNull($this->component($component)->placing);
        self::assertSame('fastest', $this->component($component)->sortBy);
    }

    public function testPlacingSortsLeavePairsAndTeamsFastestFirst(): void
    {
        $component = $this->results(self::createClient());
        $component->call('changeSortBy', ['sort' => 'worst_share']);
        $component->call('changeResultsCategory', ['category' => 'duo']);
        $crawler = $component->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-live-sort-param="worst_share"]'), 'No placing sorts offered on the pairs tab');
        self::assertCount(0, $crawler->filter('[data-testid="placing-summary"]'));
    }

    public function testPredictionSortsAreForMembersOnly(): void
    {
        $guest = $this->results(self::createClient());
        $guest->call('changeSortBy', ['sort' => 'ahead_of_prediction']);
        self::assertSame('fastest', $this->component($guest)->sortBy);
        self::assertCount(0, $guest->render()->crawler()->filter('[data-live-sort-param="ahead_of_prediction"]'), 'Locked for guests');

        $member = $this->results($this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE));
        $member->call('changeSortBy', ['sort' => 'ahead_of_prediction']);
        self::assertSame('ahead_of_prediction', $this->component($member)->sortBy);
        self::assertCount(1, $member->render()->crawler()->filter('.dropdown-item.active[data-live-sort-param="ahead_of_prediction"]'));
    }

    public function testNoPredictionSortsOnTheProfileOfAPlayerWhoTurnedPredictionsOff(): void
    {
        $component = $this->results($this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE), predictionsOptedOut: true);
        $component->call('changeSortBy', ['sort' => 'behind_prediction']);

        self::assertSame('fastest', $this->component($component)->sortBy);
        self::assertCount(0, $component->render()->crawler()->filter('[data-live-sort-param="behind_prediction"]'));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, $playerId);

        return $client;
    }

    private function results(KernelBrowser $client, bool $predictionsOptedOut = false): TestLiveComponent
    {
        $component = $this->createLiveComponent('PlayerSolvedPuzzles', [
            'playerId' => PlayerFixture::PLAYER_REGULAR,
            'predictionsOptedOut' => $predictionsOptedOut,
        ], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    private function component(TestLiveComponent $component): PlayerSolvedPuzzles
    {
        $results = $component->component();
        self::assertInstanceOf(PlayerSolvedPuzzles::class, $results);

        return $results;
    }

    /**
     * The rank of every row that has one, in the order shown
     *
     * @return list<int>
     */
    private function ranks(Crawler $crawler): array
    {
        return array_values(array_map(
            static fn (string $text): int => (int) preg_replace('/\D/', '', explode(' ', trim($text))[0]),
            $crawler->filter('.ps-table [data-testid="rank-chip"] .pr-chip-rank-place')->each(static fn (Crawler $place): string => $place->text()),
        ));
    }
}
