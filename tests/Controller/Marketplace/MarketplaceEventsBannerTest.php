<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Marketplace;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The "Marketplace at events" banner (HintType::MarketplaceAtEvents) on the marketplace and on the player's own
 * sell/swap list. States in GetMarketplaceEventsHintStateTest.
 */
final class MarketplaceEventsBannerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string STATE_SQL = 'AS has_published_listing';

    public function testABuyerGoingWherePuzzlesAreComingIsShownThem(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $banner = $this->banner($browser->request('GET', '/en/marketplace'));

        self::assertSame('buyer', $banner->attr('data-banner'));
        self::assertStringContainsString('Looking for a puzzle at Puzzle Swap Fair?', $banner->text());
        self::assertStringContainsString('2 are coming with puzzlers who are going.', $banner->text());
        self::assertSame('/en/marketplace?event=' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR, $banner->filter('a')->attr('href'));
    }

    public function testNotWhileLookingAtWhatIsComingThere(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/marketplace?event=' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="marketplace-events-banner"]'));
    }

    public function testASellerGoingSomewhereIsAskedToChooseWhatToBring(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        $banner = $this->banner($browser->request('GET', '/en/marketplace'));

        self::assertSame('going', $banner->attr('data-banner'));
        self::assertStringContainsString("You're going to Puzzle Meetup Prague · Puzzle Meetup #1 on ", $banner->text());
        self::assertSame('/en/events/' . CompetitionSeriesFixture::EDITION_OFFLINE_1 . '/what-i-bring', $banner->filter('a')->attr('href'));
    }

    public function testASellerGoingNowhereIsShownTheEvents(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->database($browser)->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );

        $banner = $this->banner($browser->request('GET', '/en/marketplace'));

        self::assertSame('seller', $banner->attr('data-banner'));
        self::assertSame('/en/events', $banner->filter('a')->attr('href'));
    }

    public function testDismissedOnceForGood(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'marketplace_at_events']);
        self::assertResponseStatusCodeSame(204);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/marketplace');
        self::assertCount(0, $crawler->filter('[data-testid="marketplace-events-banner"]'));
        // The disclaimer is untouched
        self::assertCount(1, $crawler->filter('.alert-warning'));
        // ... and the banner's state is not even asked for
        self::assertSame([], array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, self::STATE_SQL)));

        $crawler = $browser->request('GET', '/en/sell-swap-list/' . PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $crawler->filter('[data-testid="marketplace-events-banner"]'));
    }

    public function testBothHintsOfTheMarketplaceAreReadInOneQuery(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/marketplace');
        self::assertResponseIsSuccessful();

        $sql = $this->executedSql($browser);
        self::assertCount(1, array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'dismissed_hint')));
        self::assertCount(1, array_filter($sql, static fn (string $statement): bool => str_contains($statement, self::STATE_SQL)));
    }

    public function testGuestsGetNoBannerAndPayNothingForIt(): void
    {
        $browser = self::createClient();

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/marketplace');

        self::assertCount(0, $crawler->filter('[data-testid="marketplace-events-banner"]'));
        self::assertSame([], array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, self::STATE_SQL) || str_contains($sql, 'dismissed_hint')));
    }

    public function testOnTheirOwnSellSwapListOnly(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        $banner = $this->banner($browser->request('GET', '/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame('going', $banner->attr('data-banner'));

        // Someone else's list: no banner, no state query
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/sell-swap-list/' . PlayerFixture::PLAYER_ADMIN);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="marketplace-events-banner"]'));
        self::assertSame([], array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, self::STATE_SQL) || str_contains($sql, 'dismissed_hint')));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function banner(Crawler $crawler): Crawler
    {
        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-testid="marketplace-events-banner"]');
        self::assertCount(1, $banner);

        return $banner;
    }

    private function database(KernelBrowser $browser): Connection
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);

        return $connection;
    }
}
