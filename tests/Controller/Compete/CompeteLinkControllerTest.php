<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A personal link without a language (`compete_link`, `/compete/{token}` - stream RP, what the links CSV hands out):
 * the participant's page in the signed-in player's profile language, else the browser's, else English - anonymous and
 * session-free, never stored, never passed on.
 */
final class CompeteLinkControllerTest extends WebTestCase
{
    private const string LINK = '/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS;

    public function testTheBrowsersLanguageDecides(): void
    {
        $browser = self::createClient();

        $cases = [
            'cs-CZ,cs;q=0.9,en;q=0.8' => '/soutezit/',
            'de-DE,de;q=0.9' => '/de/compete/',
            'ja' => '/ja/compete/',
            'es-ES' => '/es/compete/',
            'fr-CH, fr;q=0.9, en;q=0.8' => '/fr/compete/',
            // Nothing the site speaks, or nothing said: English
            'pl-PL,pl;q=0.9' => '/en/compete/',
            '' => '/en/compete/',
        ];

        foreach ($cases as $languages => $path) {
            $browser->request('GET', self::LINK, server: $languages === '' ? [] : ['HTTP_ACCEPT_LANGUAGE' => $languages]);

            self::assertResponseStatusCodeSame(Response::HTTP_FOUND, $languages);
            self::assertSame($path . OnlineRoundsFixture::TOKEN_ANONYMOUS, $browser->getResponse()->headers->get('Location'), $languages);
            self::assertPrivate($browser);
            self::assertSame([], $browser->getResponse()->headers->getCookies(), 'an anonymous visit sets no cookie');
            self::assertFalse($browser->getRequest()->hasSession() && $browser->getRequest()->getSession()->isStarted(), 'an anonymous visit starts no session');
        }

        // Where it leads is the participant's page itself
        $browser->request('GET', self::LINK, server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.compete[data-controller="compete"]');
    }

    public function testASignedInPlayersProfileLanguageWins(): void
    {
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->update('player', ['locale' => 'cs'], ['id' => PlayerFixture::PLAYER_REGULAR]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED, server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame('/soutezit/' . OnlineRoundsFixture::TOKEN_CONNECTED, $browser->getResponse()->headers->get('Location'));
        self::assertPrivate($browser);
    }

    public function testAnUnknownLinkLandsOnTheFriendly404(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/compete/UnknownTestCupToken222', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $browser->followRedirect();
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('h1', 'This link does not open a competition');
    }

    private static function assertPrivate(KernelBrowser $browser): void
    {
        $headers = $browser->getResponse()->headers;

        self::assertTrue($headers->hasCacheControlDirective('no-store'), 'Cache-Control: ' . $headers->get('Cache-Control'));
        self::assertTrue($headers->hasCacheControlDirective('private'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
        self::assertSame('same-origin', $headers->get('Referrer-Policy'));
        self::assertSame('noindex', $headers->get('X-Robots-Tag'));
    }
}
