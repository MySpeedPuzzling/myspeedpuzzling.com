<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Head meta of every page (docs/features/navigation F-04): the viewport lets people pinch-zoom (no maximum-scale,
 * G5), the browser chrome is white like the new top bar, and the web app manifest says the same colour. The link to
 * the manifest carries a new version so browsers fetch the changed file.
 */
final class HeadMetaTest extends WebTestCase
{
    private const string THEME_COLOR = '#ffffff';
    private const string MANIFEST_PATH = __DIR__ . '/../../public/site.webmanifest';

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function viewers(): iterable
    {
        yield 'guest' => [null];
        yield 'player' => [PlayerFixture::PLAYER_REGULAR];
    }

    #[DataProvider('viewers')]
    public function testViewportAllowsZoomAndThemeColorIsWhite(null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $viewport = $crawler->filter('head meta[name="viewport"]');
        self::assertCount(1, $viewport);
        $content = (string) $viewport->attr('content');
        self::assertStringNotContainsString('maximum-scale', $content);
        self::assertStringNotContainsString('user-scalable', $content);
        self::assertStringContainsString('width=device-width', $content);
        self::assertStringContainsString('initial-scale=1', $content);
        self::assertStringContainsString('viewport-fit=cover', $content);

        $themeColor = $crawler->filter('head meta[name="theme-color"]');
        self::assertCount(1, $themeColor);
        self::assertSame(self::THEME_COLOR, $themeColor->attr('content'));

        $manifest = $crawler->filter('head link[rel="manifest"]');
        self::assertCount(1, $manifest);
        self::assertStringEndsWith('site.webmanifest?v=3', (string) $manifest->attr('href'));
    }

    public function testManifestThemeColorMatchesTheMeta(): void
    {
        self::assertFileExists(self::MANIFEST_PATH);
        $manifest = json_decode((string) file_get_contents(self::MANIFEST_PATH), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        self::assertSame(self::THEME_COLOR, $manifest['theme_color'] ?? null);
        self::assertSame('#ffffff', $manifest['background_color'] ?? null);
        self::assertSame('standalone', $manifest['display'] ?? null);
        self::assertSame('/', $manifest['start_url'] ?? null);
        // No "id": the installed app's identity is derived from start_url and stays the same
        self::assertArrayNotHasKey('id', $manifest);
        self::assertNotEmpty($manifest['icons'] ?? []);
    }
}
