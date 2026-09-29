<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Services\PuzzleTimeGuides;
use SpeedPuzzling\Web\Tests\PinsSolveTimeDistributions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapGuidesControllerTest extends WebTestCase
{
    use PinsSolveTimeDistributions;

    public function testGuidesSitemapContainsSingleLocaleEntries(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-guides.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<urlset', $content);
        self::assertStringContainsString('/en/guides</loc>', $content);
        self::assertStringContainsString('/en/guides/what-is-speed-puzzling</loc>', $content);
        self::assertStringContainsString('/en/guides/how-long-does-a-1000-piece-puzzle-take</loc>', $content);
        self::assertStringContainsString('/en/guides/average-puzzle-time-by-piece-count</loc>', $content);
        self::assertStringContainsString('/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people</loc>', $content);
        self::assertStringContainsString('/en/guides/speed-puzzling-tips</loc>', $content);

        // The fixtures are too thin for any size guide besides the original 1000-piece one
        self::assertStringNotContainsString('/en/guides/how-long-does-a-500-piece-puzzle-take</loc>', $content);

        // Guides are English-only: exactly one entry per page, no locale expansion.
        self::assertSame(6, substr_count($content, '<url>'));
    }

    public function testGuidesSitemapListsEveryLiveSizeGuide(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $browser->request('GET', '/sitemap-guides.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        foreach (PuzzleTimeGuides::GENERIC_GUIDE_PIECES as $pieces) {
            self::assertStringContainsString(sprintf('/en/guides/how-long-does-a-%d-piece-puzzle-take</loc>', $pieces), $content);
        }

        // The original 1000-piece guide is listed once, not again among the sizes
        self::assertSame(1, substr_count($content, '/en/guides/how-long-does-a-1000-piece-puzzle-take</loc>'));
        self::assertSame(6 + count(PuzzleTimeGuides::GENERIC_GUIDE_PIECES), substr_count($content, '<url>'));
    }

    public function testGuidesSitemapLeavesOutSizesBelowTheMinimum(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions([2000 => PuzzleTimeGuides::MINIMUM_SOLO_SOLVES - 1]);

        $browser->request('GET', '/sitemap-guides.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('/en/guides/how-long-does-a-1500-piece-puzzle-take</loc>', $content);
        self::assertStringNotContainsString('/en/guides/how-long-does-a-2000-piece-puzzle-take</loc>', $content);
    }
}
