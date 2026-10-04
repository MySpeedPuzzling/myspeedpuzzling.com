<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\MarketplaceListing;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The marketplace's difficulty filter: members only, kept in the URL like every marketplace filter.
 */
final class MarketplaceListingDifficultyFilterTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testMemberNarrowsTheListingsToTiers(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $component = $this->listing($browser);
        $all = $this->cards($component->render()->crawler())->count();

        $component->set('difficulty', ['5']);
        $crawler = $component->render()->crawler();
        $hard = $this->cards($crawler);

        self::assertGreaterThan(0, $hard->count());
        self::assertLessThan($all, $hard->count());
        self::assertSame(['Hard'], array_values(array_unique($crawler->filter('[data-testid="difficulty-corner"]')->each(static fn (Crawler $corner): string => (string) $corner->attr('title')))));
        self::assertCount(1, $crawler->filter('#marketplace-difficulty-5[checked]'));
        self::assertCount(0, $crawler->filter('input[data-model="difficulty[]"][disabled]'));
        self::assertStringContainsString('difficulty', urldecode($this->listingOf($component)->getReturnUrl()));

        // Not rated yet
        $component->set('difficulty', ['0']);
        self::assertCount($all - $hard->count(), $this->cards($component->render()->crawler()));

        $component->set('difficulty', []);
        self::assertCount($all, $this->cards($component->render()->crawler()));
    }

    public function testUnknownValuesAreDropped(): void
    {
        $component = $this->listing($this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE));

        $component->set('difficulty', ['9', 'x', '5', '5', '0']);

        self::assertSame(['0', '5'], $this->listingOf($component)->difficulty);
    }

    public function testWithoutMembershipTheChipsAreLockedAndIgnored(): void
    {
        foreach ([PlayerFixture::PLAYER_REGULAR, null] as $viewer) {
            $browser = $viewer === null ? $this->guest() : $this->signedIn($viewer);
            $component = $this->listing($browser);
            $crawler = $component->render()->crawler();
            $all = $this->cards($crawler)->count();

            self::assertCount(7, $crawler->filter('input[data-model="difficulty[]"][disabled]'));
            self::assertCount(1, $crawler->filter('label [data-bs-target="#membersExclusiveModal"]'));

            $component->set('difficulty', ['5']);
            self::assertCount($all, $this->cards($component->render()->crawler()));

            self::ensureKernelShutdown();
        }
    }

    public function testTheUrlCarriesTheFilter(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/marketplace?difficulty[]=5&difficulty[]=nonsense');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#marketplace-difficulty-5[checked]'));
        self::assertGreaterThan(0, $this->cards($crawler)->count());
        self::assertSame(['Hard'], array_values(array_unique($crawler->filter('[data-testid="difficulty-corner"]')->each(static fn (Crawler $corner): string => (string) $corner->attr('title')))));
    }

    public function testMemberSortsTheListingsByDifficulty(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->rateTheOldestListingEasy();
        $component = $this->listing($browser);
        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('select[data-model="sort"] option[value="easiest"]'));
        self::assertCount(1, $crawler->filter('select[data-model="sort"] option[value="hardest"]'));

        $component->set('sort', 'hardest');
        self::assertSame(['Hard', 'Easy', 'Unknown'], $this->cornerSequence($component->render()->crawler()));

        $component->set('sort', 'easiest');
        $crawler = $component->render()->crawler();
        self::assertSame(['Easy', 'Hard', 'Unknown'], $this->cornerSequence($crawler));
        self::assertCount(1, $crawler->filter('option[value="easiest"][selected]'));
        self::assertStringContainsString('sort=easiest', $this->listingOf($component)->getReturnUrl());

        // Only the rated tiers asked for, still in order
        $component->set('difficulty', ['2', '5']);
        self::assertSame(['Easy', 'Hard'], $this->cornerSequence($component->render()->crawler()));
    }

    public function testWithoutMembershipTheDifficultySortIsNotOfferedAndIgnored(): void
    {
        foreach ([PlayerFixture::PLAYER_REGULAR, null] as $viewer) {
            $browser = $viewer === null ? $this->guest() : $this->signedIn($viewer);
            $this->rateTheOldestListingEasy();
            $component = $this->listing($browser);
            $crawler = $component->render()->crawler();
            $newest = $this->cards($crawler)->each(static fn (Crawler $card): string => (string) $card->attr('id'));

            self::assertCount(0, $crawler->filter('select[data-model="sort"] option[value="easiest"], select[data-model="sort"] option[value="hardest"]'));

            $component->set('sort', 'easiest');
            $crawler = $component->render()->crawler();
            self::assertSame('newest', $this->listingOf($component)->sort);
            self::assertSame($newest, $this->cards($crawler)->each(static fn (Crawler $card): string => (string) $card->attr('id')));

            self::ensureKernelShutdown();
        }
    }

    public function testTheUrlCarriesTheDifficultySort(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->rateTheOldestListingEasy();

        $crawler = $browser->request('GET', '/en/marketplace?sort=easiest');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('option[value="easiest"][selected]'));
        self::assertSame(['Easy', 'Hard', 'Unknown'], $this->cornerSequence($crawler));
    }

    /**
     * Besides the newest listing's Hard puzzle (guest()), the oldest listing's puzzle is Easy
     */
    private function rateTheOldestListingEasy(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $puzzleId = $connection->fetchOne('SELECT puzzle_id FROM sell_swap_list_item WHERE published_on_marketplace = true ORDER BY added_at ASC LIMIT 1');
        self::assertIsString($puzzleId);

        $connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_tier, difficulty_score, confidence, sample_size, computed_at) VALUES (:puzzleId, 2, 0.8, 'high', 10, NOW())",
            ['puzzleId' => $puzzleId],
        );
    }

    /**
     * @return list<string> the tier names on the cards in their order, each run of one name once
     */
    private function cornerSequence(Crawler $crawler): array
    {
        $names = $this->cards($crawler)->each(static fn (Crawler $card): string => (string) $card->filter('[data-testid="difficulty-corner"]')->attr('title'));
        $sequence = [];

        foreach ($names as $name) {
            if ($sequence === [] || $sequence[array_key_last($sequence)] !== $name) {
                $sequence[] = $name;
            }
        }

        return $sequence;
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = $this->guest();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    /**
     * One listed puzzle is Hard, every other one is not rated yet
     */
    private function guest(): KernelBrowser
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);

        $puzzleId = $connection->fetchOne('SELECT puzzle_id FROM sell_swap_list_item WHERE published_on_marketplace = true ORDER BY added_at DESC LIMIT 1');
        self::assertIsString($puzzleId);

        $connection->executeStatement('DELETE FROM puzzle_difficulty');
        $connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_tier, difficulty_score, confidence, sample_size, computed_at) VALUES (:puzzleId, 5, 1.3, 'high', 10, NOW())",
            ['puzzleId' => $puzzleId],
        );

        return $browser;
    }

    private function listing(KernelBrowser $browser): TestLiveComponent
    {
        $component = $this->createLiveComponent('MarketplaceListing', [], $browser);
        $component->setRouteLocale('en');

        return $component;
    }

    private function listingOf(TestLiveComponent $component): MarketplaceListing
    {
        $listing = $component->component();
        self::assertInstanceOf(MarketplaceListing::class, $listing);

        return $listing;
    }

    private function cards(Crawler $crawler): Crawler
    {
        return $crawler->filter('[id^="marketplace-listing-"]');
    }
}
