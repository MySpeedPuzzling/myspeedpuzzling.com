<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapBrandsControllerTest extends WebTestCase
{
    public function testSitemapContainsIndexableBrandsAndPiecesHubs(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-brands.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<urlset', $content);
        self::assertStringContainsString('/en/puzzle/brand/ravensburger', $content);
        self::assertStringContainsString('/puzzle/znacka/ravensburger', $content);
        self::assertStringContainsString('/en/puzzle/1000-pieces', $content);
        self::assertStringContainsString('/puzzle/1000-dilku', $content);

        // Thin brand (single unapproved puzzle, no solves) must not be listed
        self::assertStringNotContainsString('unknown-brand', $content);

        self::assertStringNotContainsString('xhtml:link', $content);
    }
}
