<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Browse all puzzlers". Public fixture players: John Doe + Admin User (cz), Michael Johnson (de), Sarah Williams (gb),
 * Dana Twin + Tom Twin (no country). Jane Smith (us) is private; PLAYER_REGULAR blocks her.
 */
final class PlayersDirectoryControllerTest extends WebTestCase
{
    public function testTheBareWorldPageIsIndexable(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/puzzlers/all');

        self::assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSelectorTextContains('h1', 'All puzzlers');
        // Every person is a player card trigger (data-player-card, assets/player_card.js - CARD-01a)
        self::assertSelectorExists('.players-page a.players-directory-card[data-player-card][aria-haspopup="dialog"]');

        $names = $this->names($crawler);
        self::assertContains('John Doe', $names);
        self::assertContains('Sarah Williams', $names);
        self::assertContains('Dana Twin', $names);
        self::assertNotContains('Jane Smith', $names);
        self::assertSelectorTextContains('.players-directory-count', '12 puzzlers');
    }

    public function testAnyQueryStringIsANoindexVariant(): void
    {
        $browser = self::createClient();

        foreach (['/en/puzzlers/all?scope=cz', '/en/puzzlers/all?sort=name', '/en/puzzlers/all?active=1', '/en/puzzlers/all?limit=48', '/en/puzzlers/all?scope='] as $path) {
            $crawler = $browser->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'), $path);
        }
    }

    public function testACountryScope(): void
    {
        $browser = self::createClient();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        $crawler = $browser->request('GET', '/en/puzzlers/all?scope=cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'All puzzlers from Czechia');
        self::assertEqualsCanonicalizing(['John Doe', 'Admin User'], $this->names($crawler));
        self::assertSame('cz', $crawler->filter('#players-directory-scope option[selected]')->attr('value'));
        self::assertSelectorExists('.players-directory-back a[href="/en/puzzlers?scope=cz"]');
    }

    public function testFiltersAndSortComeFromTheUrl(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/puzzlers/all?swaps=1&sort=name');

        self::assertResponseIsSuccessful();
        // Sell/swap lists: Admin User and Sarah Williams
        self::assertSame(['Admin User', 'Sarah Williams'], $this->names($crawler));
        self::assertSelectorExists('input[name="swaps"][checked]');
        self::assertSelectorNotExists('input[name="active"][checked]');
        self::assertSame('name', $crawler->filter('#players-directory-sort option[selected]')->attr('value'));
        self::assertSelectorTextContains('.players-directory-card-chips', 'Swaps');
    }

    public function testNobodyMatchingOffersToClearTheFilters(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/puzzlers/all?scope=cz&instagram=1&sort=recent');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->names($crawler));
        self::assertSelectorTextContains('.players-directory-empty', 'Nobody matches all of these filters yet.');
        self::assertSame(
            '/en/puzzlers/all?scope=cz&sort=recent#players-directory',
            $crawler->filter('.players-directory-empty a')->attr('href'),
        );
    }

    public function testCardsOpenThePlayerCardAndLinkTheProfile(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/puzzlers/all?scope=gb');

        $card = $crawler->filter('.players-directory-card');
        self::assertCount(1, $card);
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $card->attr('href'));
        // The link opens the player card (CARD-01a): the trigger's player is the href's
        self::assertNotNull($card->attr('data-player-card'));
        self::assertSame('dialog', $card->attr('aria-haspopup'));
        // Every player fits on one page - no "Show more"
        self::assertSelectorNotExists('.players-directory-more');
    }

    public function testTheViewersBlocksApply(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_private = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_PRIVATE],
        );

        $crawler = $browser->request('GET', '/en/puzzlers/all');
        self::assertContains('Jane Smith', $this->names($crawler));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/puzzlers/all');

        self::assertResponseIsSuccessful();
        self::assertNotContains('Jane Smith', $this->names($crawler));
        self::assertContains('John Doe', $this->names($crawler));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('.players-directory-card-name')->each(
            static fn (Crawler $name): string => trim($name->text()),
        );
    }
}
