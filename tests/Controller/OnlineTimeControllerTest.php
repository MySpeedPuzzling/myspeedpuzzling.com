<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * `GET /-/time` - the clock sync's sample (docs/features/online-competitions/README.md §5.2).
 */
final class OnlineTimeControllerTest extends WebTestCase
{
    public function testTheServersTimeInMilliseconds(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/time');

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $json = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame(['serverNowMs'], array_keys($json));
        self::assertIsInt($json['serverNowMs']);
        // The app's clock, frozen in tests - exact
        self::assertSame(TestClock::milliseconds(), $json['serverNowMs']);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testNoSessionAndNoCookieEvenForASignedInBrowser(): void
    {
        $browser = self::createClient();
        // A cookie a signed-in browser would send along
        $browser->getCookieJar()->set(new Cookie('PHPSESSID', 'some-session'));

        $browser->request('GET', '/-/time');

        self::assertResponseIsSuccessful();
        self::assertSame([], $browser->getResponse()->headers->getCookies());
    }

    public function testOnlyGet(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/time');

        self::assertResponseStatusCodeSame(405);
    }
}
