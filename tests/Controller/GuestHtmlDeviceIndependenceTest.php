<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guest HTML is the same for every browser (docs/features/navigation GEST-09, README §4.13): the device OS is set by a
 * head script in the browser, never by the server, so an iPhone, a Pixel and a desktop get byte-identical pages (the
 * render timestamp aside) and the page stays shared-cacheable.
 */
final class GuestHtmlDeviceIndependenceTest extends WebTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function guestPages(): array
    {
        return [
            'FAQ' => ['/en/faq'],
            'puzzle page' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01],
            'homepage' => ['/'],
        ];
    }

    #[DataProvider('guestPages')]
    public function testEveryBrowserGetsTheSamePage(string $url): void
    {
        $browser = self::createClient();
        $bodies = [];

        foreach (NativeUserAgents::web() as $name => [$userAgent]) {
            $browser->request('GET', $url, server: ['HTTP_USER_AGENT' => $userAgent]);

            self::assertResponseIsSuccessful($name);
            $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('public', $cacheControl, $name);
            self::assertStringContainsString('s-maxage=', $cacheControl, $name);
            self::assertStringNotContainsString('User-Agent', (string) $browser->getResponse()->headers->get('Vary'), $name);

            $bodies[$name] = self::normalised((string) $browser->getResponse()->getContent());
        }

        self::assertStringContainsString('data-os', $bodies['desktop Chrome'], 'The head script that sets data-os is on the page');
        self::assertStringNotContainsString('data-native-app=', $bodies['desktop Chrome']);
        self::assertSame($bodies['desktop Chrome'], $bodies['iPhone Safari']);
        self::assertSame($bodies['desktop Chrome'], $bodies['Pixel Chrome']);
    }

    /** The only per-request value of a guest page: when it was rendered (the stale-document check reads it) */
    private static function normalised(string $html): string
    {
        return (string) preg_replace('~<meta name="msp-rendered-at" content="\d+">~', '<meta name="msp-rendered-at" content="">', $html);
    }
}
