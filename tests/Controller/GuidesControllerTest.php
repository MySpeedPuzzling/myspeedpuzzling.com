<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GuidesControllerTest extends WebTestCase
{
    public function testGuidesIndexIsAccessible(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/guides');

        $this->assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('h1'));
        self::assertStringContainsString('Speed Puzzling Guides', $crawler->filter('h1')->text());

        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('/en/guides/what-is-speed-puzzling', $content);
        self::assertStringContainsString('/en/guides/how-long-does-a-1000-piece-puzzle-take', $content);
        self::assertStringContainsString('/en/guides/speed-puzzling-tips', $content);
    }

    public function testWhatIsSpeedPuzzlingGuide(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/guides/what-is-speed-puzzling');

        $this->assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('h1'));
        self::assertStringContainsString('What Is Speed Puzzling?', $crawler->filter('h1')->text());

        $this->assertArticleJsonLdIsValid((string) $browser->getResponse()->getContent());
    }

    public function testHowLongGuideRendersRealStatistics(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/guides/how-long-does-a-1000-piece-puzzle-take');

        $this->assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('h1'));
        self::assertStringContainsString('How Long Does a 1000-Piece Puzzle Take?', $crawler->filter('h1')->text());

        $content = (string) $browser->getResponse()->getContent();

        // The lead answer must be computed from real (fixture) data - non-zero solve count.
        self::assertSame(
            1,
            preg_match('/Across ([\d,]+) solo solves recorded on MySpeedPuzzling/', $content, $matches),
            'Lead paragraph with the measured answer should be rendered',
        );
        self::assertGreaterThan(0, (int) str_replace(',', '', $matches[1]));

        // The comparison table must contain the 1000-pieces bucket.
        self::assertStringContainsString('Median time', $content);

        $this->assertArticleJsonLdIsValid($content);
    }

    public function testSpeedPuzzlingTipsGuide(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/guides/speed-puzzling-tips');

        $this->assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('h1'));
        self::assertStringContainsString('Speed Puzzling Tips', $crawler->filter('h1')->text());

        $this->assertArticleJsonLdIsValid((string) $browser->getResponse()->getContent());
    }

    public function testGuidesEmitSingleEnglishHreflang(): void
    {
        $browser = self::createClient();

        $paths = [
            '/en/guides',
            '/en/guides/what-is-speed-puzzling',
            '/en/guides/how-long-does-a-1000-piece-puzzle-take',
            '/en/guides/speed-puzzling-tips',
        ];

        foreach ($paths as $path) {
            $browser->request('GET', $path);

            $this->assertResponseIsSuccessful();

            $content = (string) $browser->getResponse()->getContent();

            // English-only pages: exactly one "en" alternate + one x-default, both self-referencing.
            self::assertSame(2, substr_count($content, 'rel="alternate" hreflang='), sprintf('Page "%s" must emit exactly 2 hreflang alternates', $path));
            self::assertSame(1, preg_match('/<link rel="alternate" hreflang="en" href="([^"]+)">/', $content, $enMatch), $path);
            self::assertSame(1, preg_match('/<link rel="alternate" hreflang="x-default" href="([^"]+)">/', $content, $defaultMatch), $path);
            self::assertStringEndsWith($path, $enMatch[1]);
            self::assertSame($enMatch[1], $defaultMatch[1]);
        }
    }

    private function assertArticleJsonLdIsValid(string $content): void
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        self::assertNotEmpty($matches[1], 'Page should contain JSON-LD scripts');

        $types = [];

        foreach ($matches[1] as $json) {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($decoded);

            if (isset($decoded['@type']) && is_string($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }

        self::assertContains('Article', $types, 'Page should contain Article JSON-LD');
        self::assertContains('BreadcrumbList', $types, 'Page should contain BreadcrumbList JSON-LD');
    }
}
