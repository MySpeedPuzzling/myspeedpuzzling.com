<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

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

    public function testUndatedEditionWithoutRoundsIsListedWithDateNotSet(): void
    {
        $browser = self::createClient();
        $undatedId = self::addUndatedEdition();

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $card = $crawler->filter(sprintf('[data-series-edition="%s"]', $undatedId));
        self::assertCount(1, $card, 'An edition without a date and without rounds must never vanish from the series page');
        self::assertStringContainsString('Pinecone Speed Cup No. 17', $card->text());
        self::assertSame('Date not set', trim($card->filter('[data-edition-date-not-set]')->text()));

        // With the upcoming editions - the Next card first, the undated one last
        $upcomingCards = $crawler->filter('[data-series-edition]');
        self::assertSame($undatedId, $upcomingCards->last()->attr('data-series-edition'));
        self::assertSame(CompetitionSeriesFixture::EDITION_EJJ_69, $upcomingCards->first()->attr('data-series-edition'));
    }

    public function testJsonLdLeavesOutEditionsWithoutAStartDate(): void
    {
        $browser = self::createClient();
        self::addUndatedEdition();

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $series = self::eventSeriesJsonLd((string) $browser->getResponse()->getContent());
        self::assertIsArray($series['subEvent'] ?? null);
        $names = [];
        foreach ($series['subEvent'] as $subEvent) {
            self::assertIsArray($subEvent);
            self::assertIsString($subEvent['startDate'] ?? null, 'Every sub-event has a startDate');
            $names[] = $subEvent['name'] ?? null;
        }
        // One sub-event per session, named like the event (series · edition)
        self::assertContains('Euro Jigsaw Jam · EJJ #69 — May 2026', $names);
        self::assertNotContains('Pinecone Speed Cup No. 17', $names);
    }

    public function testEditionCardShowsTheEditionsOwnLogo(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET logo = 'ejj-69-logo.png' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_69],
        );

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $logo = $crawler->filter(sprintf('[data-series-edition="%s"] img', CompetitionSeriesFixture::EDITION_EJJ_69));
        self::assertCount(1, $logo);
        self::assertStringEndsWith('/preset:puzzle_small/plain/ejj-69-logo.png', (string) $logo->attr('src'));
        // An edition without its own logo gets none on its card - the series logo is in the page header
        self::assertCount(0, $crawler->filter(sprintf('[data-series-edition="%s"] img', CompetitionSeriesFixture::EDITION_EJJ_68)));
    }

    public function testUndatedEditionIsListedOnTheManagementPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $undatedId = self::addUndatedEdition();

        $crawler = $browser->request('GET', '/en/manage-series/' . CompetitionSeriesFixture::SERIES_EJJ);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Pinecone Speed Cup No. 17', $crawler->filter('main')->text());
        self::assertSame('Date not set', trim($crawler->filter('[data-edition-date-not-set]')->text()));
        // ... with its edit and delete buttons, so the organiser can fix or remove it
        self::assertCount(1, $crawler->filter(sprintf('a[href^="/en/edit-event/%s"]', $undatedId)));
        self::assertCount(1, $crawler->filter(sprintf('#deleteEditionModal-%s', $undatedId)));
    }

    /**
     * A duplicate like the one on production: no date, no rounds.
     */
    private static function addUndatedEdition(): string
    {
        $editionId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddEdition(
            competitionId: $editionId,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'Pinecone Speed Cup No. 17',
            dateFrom: null,
            dateTo: null,
            registrationLink: null,
            resultsLink: null,
        ));

        return $editionId->toString();
    }

    /**
     * @return array<mixed>
     */
    private static function eventSeriesJsonLd(string $content): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'EventSeries') {
                return $decoded;
            }
        }

        self::fail('The series page emits EventSeries JSON-LD');
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
