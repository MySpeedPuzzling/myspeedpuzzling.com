<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionSeriesDetailControllerTest extends WebTestCase
{
    public function testApprovedSeriesIsIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="index, follow"]');
        self::assertCount(1, $crawler->filter('meta[name="robots"]'));
    }

    public function testUnapprovedSeriesIsNotIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/pending-puzzle-league');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertCount(1, $crawler->filter('meta[name="robots"]'));
    }

    public function testRejectedSeriesIsNotIndexable(): void
    {
        $browser = self::createClient();
        // approve() and reject() do not clear each other - a rejection vetoes a stale approval
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testJsonLdImageIsTheStrippedMediumLogo(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_series SET logo = 'ejj-logo.png' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', (string) $browser->getResponse()->getContent(), $matches);

        $images = [];
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'EventSeries') {
                $images[] = $decoded['image'] ?? null;
            }
        }

        self::assertCount(1, $images, 'The series page emits one EventSeries JSON-LD');
        self::assertIsString($images[0]);
        // The large stripped preset, never the uploaded original (may carry EXIF/GPS)
        self::assertStringEndsWith('/preset:puzzle_large/plain/ejj-logo.png', $images[0]);
    }
}
