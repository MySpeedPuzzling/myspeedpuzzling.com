<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Czechia's public fixture players: John Doe (PLAYER_REGULAR) and Admin User (PLAYER_ADMIN). The only player from the
 * US is the private Jane Smith (PLAYER_PRIVATE), whom PLAYER_REGULAR blocks.
 */
final class PlayersPerCountryControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/players-from-country/cz');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/players-from-country/cz');

        $this->assertResponseIsSuccessful();
    }

    public function testCountryWithPlayersIsIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/players-from-country/cz');

        $this->assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testTheSpotlightThenTheDirectoryWithTheCountryFixed(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/players-from-country/cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Speed Puzzlers from Czechia');
        self::assertSelectorExists('h1 .fi-cz');
        self::assertSelectorExists('.players-page[data-controller="players-card"] .players-spotlight');
        self::assertSelectorExists('.players-page .players-directory');
        self::assertEqualsCanonicalizing(['John Doe', 'Admin User'], $this->names($crawler));
        // The country is the page: no "Where", the filters submit to this page, the way back keeps the country
        self::assertSelectorNotExists('#players-directory-scope');
        self::assertSame('/en/players-from-country/cz', $crawler->filter('.players-directory-filters')->attr('action'));
        self::assertSelectorExists('.players-directory-back a[href="/en/puzzlers?scope=cz"]');
    }

    public function testFiltersNarrowTheCountryAndAreNotForTheIndex(): void
    {
        $browser = self::createClient();

        // Of Czechia's two, only Admin User has a sell/swap list
        $crawler = $browser->request('GET', '/en/players-from-country/cz?swaps=1');

        self::assertResponseIsSuccessful();
        self::assertSame(['Admin User'], $this->names($crawler));
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testCountryWithoutPublicPlayersIsNoindexButStillAPage(): void
    {
        $browser = self::createClient();
        // PLAYER_PRIVATE is from "us" - make sure nobody public is; "aq" has nobody at all
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE player SET is_private = true WHERE country = 'us'");

        foreach (['/en/players-from-country/us', '/en/players-from-country/aq'] as $path) {
            $crawler = $browser->request('GET', $path);

            $this->assertResponseIsSuccessful();
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'), $path);
            self::assertStringContainsString('has a public profile yet', $crawler->filter('body')->text(), $path);
        }
    }

    public function testTheRobotsRuleDoesNotDependOnTheViewersBlocks(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_private = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_PRIVATE],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/players-from-country/us');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->names($crawler));
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testAnUnknownCountryIsNotFound(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/players-from-country/xx');

        self::assertResponseStatusCodeSame(404);
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
