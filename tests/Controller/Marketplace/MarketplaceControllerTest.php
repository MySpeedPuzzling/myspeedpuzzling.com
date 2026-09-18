<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Marketplace;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\MarketplaceListing;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class MarketplaceControllerTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    private const string PUZZLE_OVERVIEW_SQL = 'puzzle_statistics.fastest_time_team' . "\nFROM puzzle\nLEFT JOIN puzzle_statistics";

    /**
     * The controller, then the listing's name, image and image ratio each loaded the same
     * puzzle overview - 4 identical queries per GET marketplace_puzzle.
     */
    public function testPuzzleFilteredPageLoadsThePuzzleOverviewOnce(): void
    {
        $browser = self::createClient();
        $this->givePuzzleAnImage($browser);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/marketplace/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Puzzle 1', $crawler->filter('title')->text());
        self::assertSame('Showing offers for Puzzle 1', $crawler->filter('small:contains("Showing offers for")')->text());
        $filterImage = $crawler->filter('.card.border-primary img[src*="marketplace-test.jpg"]');
        self::assertCount(1, $filterImage);
        // image ratio 1.5 at 55 px
        self::assertSame('55', $filterImage->attr('width'));
        self::assertSame('37', $filterImage->attr('height'));
        self::assertCount(1, $this->puzzleOverviewQueries($browser));
    }

    public function testListingReRenderLoadsThePuzzleOverviewOnce(): void
    {
        $browser = self::createClient();
        $this->givePuzzleAnImage($browser);

        $component = $this->createLiveComponent(MarketplaceListing::class, ['puzzleId' => PuzzleFixture::PUZZLE_500_01], $browser);
        $component->setRouteLocale('en');
        // Initial render first, so only the re-render (live action) is counted
        $component->render();

        $this->startCountingQueries($browser);
        $html = $component->call('loadMore')->render()->toString();

        self::assertStringContainsString('Showing offers for <strong>Puzzle 1</strong>', $html);
        self::assertStringContainsString('marketplace-test.jpg', $html);
        self::assertCount(1, $this->puzzleOverviewQueries($browser));
    }

    public function testUnknownPuzzleShowsTheGenericMarketplace(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/marketplace/puzzle/018d0003-0000-0000-0000-999999999999');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('small:contains("Showing offers for")'));
    }

    private function givePuzzleAnImage(KernelBrowser $browser): void
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE puzzle SET image = 'marketplace-test.jpg', image_ratio = 1.5 WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_500_01],
        );
    }

    /**
     * @return list<string>
     */
    private function puzzleOverviewQueries(KernelBrowser $browser): array
    {
        $profile = $browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        /** @var array<string, list<array{sql: string}>> $queriesByConnection */
        $queriesByConnection = $collector->getQueries();
        $matching = [];

        foreach ($queriesByConnection as $queries) {
            foreach ($queries as $query) {
                if (str_contains($query['sql'], self::PUZZLE_OVERVIEW_SQL)) {
                    $matching[] = $query['sql'];
                }
            }
        }

        return $matching;
    }

    public function testPageLoadsForAnonymousUser(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
    }

    public function testPageLoadsForAuthenticatedUser(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
    }

    public function testPageContainsMarketplaceContent(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Marketplace');
    }

    public function testDisclaimerIsVisibleForAnonymousUser(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-warning');
    }

    public function testDisclaimerIsVisibleForUserWhoHasNotDismissed(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-warning');
    }

    public function testDisclaimerIsHiddenForUserWhoHasDismissed(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', '/en/marketplace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.alert-warning');
    }

    public function testDisclaimerIsHiddenAfterDismissing(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Disclaimer is visible before dismissing
        $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-warning');

        // Dismiss the hint
        $browser->request('POST', '/en/dismiss-hint', ['type' => 'marketplace_disclaimer']);
        $this->assertResponseStatusCodeSame(204);

        // Disclaimer is hidden after reload
        $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.alert-warning');
    }
}
