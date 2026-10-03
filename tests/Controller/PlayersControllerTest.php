<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayersControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzlers');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzlers');

        $this->assertResponseIsSuccessful();
    }

    public function testThePageOpensOnTheWorld(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.players-scope-option.is-active', 'World');
        self::assertSelectorExists('meta[name="robots"][content="index, follow"]');
    }

    public function testAScopeOpensThatCountryAsANoindexVariant(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.players-scope-option.is-active', 'Czechia');
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
    }

    public function testAnUnknownScopeIsTheWorld(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzlers?scope=xx');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.players-scope-option.is-active', 'World');
    }

    public function testASignedInPlayerGetsTheirCountryOneTapAway(): void
    {
        $browser = self::createClient();
        // John Doe lives in Czechia
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.players-scope-switch a[href="/en/puzzlers?scope=cz"]');
    }

    public function testTheCountryTypeaheadListsWorldFirstThenTheCountriesWithTheMostPuzzlers(): void
    {
        $browser = self::createClient();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        $crawler = $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select#players-scope-select[data-controller="country-typeahead"]');
        self::assertCount(1, $select);

        $options = $select->filter('option');
        self::assertSame('world', $options->first()->attr('value'));
        self::assertSame('bi bi-globe2', $options->first()->attr('data-icon'));
        self::assertSame('cz', $select->filter('option[selected]')->attr('value'));

        $counts = $options->slice(1)->each(static fn ($option): int => (int) str_replace(',', '', (string) $option->attr('data-count')));
        self::assertNotEmpty($counts);
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts, 'Most puzzlers first');
        self::assertSame('fi fi-cz', $select->filter('option[value="cz"]')->attr('data-icon'));
    }

    public function testThePageNoLongerListsTheViewersFavorites(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.players-favorites');
    }
}
