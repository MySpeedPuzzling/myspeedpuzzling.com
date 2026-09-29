<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapEventsControllerTest extends WebTestCase
{
    public function testListsEveryEditionOfAnApprovedSeries(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        // Editions are never approved individually - the series approval makes them public
        self::assertStringContainsString('/en/series/euro-jigsaw-jam-series/ejj-68-february-2026</loc>', $content);
        self::assertStringContainsString('/en/series/euro-jigsaw-jam-series/ejj-69-may-2026</loc>', $content);
        self::assertStringContainsString('/en/series/puzzle-meetup-prague/puzzle-meetup-1</loc>', $content);
        self::assertStringContainsString('/en/series/berlin-puzzle-cup/berlin-puzzle-cup-2026</loc>', $content);
        self::assertStringContainsString('/de/series/euro-jigsaw-jam-series/ejj-68-february-2026</loc>', $content);
    }

    public function testLeavesOutEditionsOfAnUnapprovedSeries(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('/pending-puzzle-league', (string) $browser->getResponse()->getContent());
    }

    public function testLeavesOutEditionsOfARejectedSeriesAndRejectedEditions(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE competition_series SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::SERIES_PAST_ONLY],
        );
        $connection->executeStatement(
            'UPDATE competition SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_69],
        );

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('/berlin-puzzle-cup', $content);
        self::assertStringNotContainsString('/ejj-69-may-2026</loc>', $content);
        self::assertStringContainsString('/en/series/euro-jigsaw-jam-series/ejj-68-february-2026</loc>', $content);
    }
}
