<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `?search=` links keep working without JavaScript (docs/features/players-page/README.md, stream S1): the Players
 * page renders the instant search's results on the server.
 */
final class PlayersSearchPageTest extends WebTestCase
{
    public function testASearchLinkRendersTheResults(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzlers?search=Sarah');

        self::assertResponseIsSuccessful();
        self::assertSame('Sarah', $crawler->filter('input.players-search-input')->attr('value'));
        self::assertCount(1, $crawler->filter('.players-search-result a.players-person[href="/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE . '"]'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
        // Guest pages stay shared-cacheable
        self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheFormIsAPlainGetThatKeepsTheScope(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzlers?scope=cz');

        $form = $crawler->filter('form.players-search-form');
        self::assertSame('get', strtolower((string) $form->attr('method')));
        self::assertSame('/en/puzzlers', $form->attr('action'));
        self::assertSame('cz', $form->filter('input[type="hidden"][name="scope"]')->attr('value'));
        self::assertCount(1, $form->filter('input[name="search"][data-model="debounce(250)|query"]'));

        $crawler = $browser->request('GET', '/en/puzzlers');
        self::assertCount(0, $crawler->filter('form.players-search-form input[name="scope"]'), 'The world is the default - not in the URL');
        self::assertCount(0, $crawler->filter('.players-search-result'));
    }

    public function testANoMatchSaysWhatToTry(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzlers?search=Zyxwvut');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Try a part of the name or the #code', $crawler->filter('.players-search-message')->text());
    }
}
