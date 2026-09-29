<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapCountriesControllerTest extends WebTestCase
{
    public function testListsOnlyCountriesWhosePageShowsPlayers(): void
    {
        $browser = self::createClient();
        // PLAYER_PRIVATE is from "us" - make sure nobody public is, so its page is empty and noindex
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE player SET is_private = true WHERE country = 'us'");

        $browser->request('GET', '/sitemap-countries.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        foreach (['cz', 'de', 'gb'] as $country) {
            self::assertStringContainsString(sprintf('/en/players-from-country/%s</loc>', $country), $content);
        }

        self::assertStringNotContainsString('/players-from-country/us</loc>', $content);
        self::assertStringNotContainsString('/hraci-dle-zeme/us</loc>', $content);
    }
}
