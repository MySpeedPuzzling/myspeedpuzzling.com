<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapGuidesControllerTest extends WebTestCase
{
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
        self::assertStringContainsString('/en/guides/speed-puzzling-tips</loc>', $content);

        // Guides are English-only: exactly one entry per page, no locale expansion.
        self::assertSame(4, substr_count($content, '<url>'));
    }
}
