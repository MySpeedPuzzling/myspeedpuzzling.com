<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditionDetailControllerTest extends WebTestCase
{
    private const string PAST_EDITION_URL = '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026';
    private const string UPCOMING_EDITION_URL = '/en/series/euro-jigsaw-jam-series/ejj-69-may-2026';

    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
    }

    public function testAddMyTimeLinkIsShownToLoggedInPlayerOnPastEdition(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(self::addTimeLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_68));
    }

    public function testAddMyTimeLinkIsHiddenFromAnonymousVisitor(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_68));
    }

    public function testAddMyTimeLinkIsHiddenOnUpcomingEdition(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::UPCOMING_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_69));
    }

    private static function addTimeLinkSelector(string $competitionId): string
    {
        return sprintf('a[href$="?competition=%s"]', $competitionId);
    }

    public function testVisitorIsOfferedImGoing(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::UPCOMING_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(self::joinLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_69), "I'm going!");
    }

    public function testSignedInPlayerIsOfferedImGoing(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::UPCOMING_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(self::joinLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_69), "I'm going!");
        $this->assertSelectorNotExists(self::leaveFormSelector(CompetitionSeriesFixture::EDITION_EJJ_69));
    }

    public function testPlayerWhoJoinedTheEditionSeesTheyAreGoing(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // The edition has no participant list - "I'm going" joins at once and comes back here
        $browser->request('GET', '/en/join-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $this->assertResponseRedirects(self::UPCOMING_EDITION_URL);
        $browser->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('span.btn-success', "You're going!");
        $this->assertSelectorExists(self::leaveFormSelector(CompetitionSeriesFixture::EDITION_EJJ_69));
        // Nobody on the organizer's list to switch to - no "Change", and "I'm going" is gone
        $this->assertSelectorNotExists(self::joinLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_69));
    }

    private static function joinLinkSelector(string $competitionId): string
    {
        return sprintf('a[href="/en/join-event/%s"]', $competitionId);
    }

    private static function leaveFormSelector(string $competitionId): string
    {
        return sprintf('form[action="/en/leave-event/%s"]', $competitionId);
    }

    public function testRoundLinksToItsResults(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="' . self::PAST_EDITION_URL . '/results/main-round"]');
    }

    public function testPastEditionTitleNamesItsSeriesAndSaysResults(): void
    {
        $browser = self::createClient();
        self::linkResultToThePastEdition();

        $crawler = $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        // The edition name carries the year already, the series makes "EJJ #68" findable
        self::assertSame('Euro Jigsaw Jam · EJJ #68 — February 2026 Results – MySpeedPuzzling', $crawler->filter('title')->text());
        // The heading stays the edition's own name
        self::assertSame('EJJ #68 — February 2026', trim($crawler->filter('h1')->text()));
    }

    public function testPastEditionWithoutResultsIsNamedLikeAnUpcomingOne(): void
    {
        $browser = self::createClient();

        // No fixture result is linked to EJJ #68 - a "Results" title would disappoint searchers
        $crawler = $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame('Euro Jigsaw Jam · EJJ #68 — February 2026 – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertStringStartsWith(
            'EJJ #68 — February 2026 — Euro Jigsaw Jam speed puzzling competition on ',
            (string) $crawler->filter('meta[name="description"]')->attr('content'),
        );
    }

    public function testPastEditionTitleIsLocalized(): void
    {
        $browser = self::createClient();
        self::linkResultToThePastEdition();

        $crawler = $browser->request('GET', '/de/series/euro-jigsaw-jam-series/ejj-68-february-2026');

        $this->assertResponseIsSuccessful();
        self::assertSame('Euro Jigsaw Jam · EJJ #68 — February 2026 Ergebnisse – MySpeedPuzzling', $crawler->filter('title')->text());
    }

    public function testUpcomingEditionTitleIsItsFullName(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::UPCOMING_EDITION_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame('Euro Jigsaw Jam · EJJ #69 — May 2026 – MySpeedPuzzling', $crawler->filter('title')->text());
    }

    public function testEditionNamedAfterItsSeriesIsNotPrefixed(): void
    {
        $browser = self::createClient();

        // "Berlin Puzzle Cup 2026" of the series "Berlin Puzzle Cup", 45 days ago, no results here
        $crawler = $browser->request('GET', '/en/series/berlin-puzzle-cup/berlin-puzzle-cup-2026');

        $this->assertResponseIsSuccessful();
        self::assertSame('Berlin Puzzle Cup 2026 – MySpeedPuzzling', $crawler->filter('title')->text());
    }

    public function testEditionWithoutDatesIsDatedByItsRounds(): void
    {
        $browser = self::createClient();
        self::linkResultToThePastEdition();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition SET name = 'EJJ #68', date_from = NULL, date_to = NULL WHERE id = :id",
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_68],
        );
        $roundStart = $connection->fetchOne(
            'SELECT starts_at FROM competition_round WHERE id = :id',
            ['id' => CompetitionSeriesFixture::ROUND_EJJ_68],
        );
        self::assertIsString($roundStart);

        $crawler = $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        // Its only round was 30 days ago - the edition is over, and the round tells the year
        self::assertSame(
            sprintf('Euro Jigsaw Jam · EJJ #68 %s Results – MySpeedPuzzling', new DateTimeImmutable($roundStart)->format('Y')),
            $crawler->filter('title')->text(),
        );
    }

    public function testPastEditionMetaDescriptionSaysHowManyResultsThereAre(): void
    {
        $browser = self::createClient();
        self::linkResultToThePastEdition();

        $crawler = $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith(
            'EJJ #68 — February 2026 results: 1 time added by puzzlers, plus the rounds, puzzles and participants. Euro Jigsaw Jam speed puzzling competition held on ',
            (string) $crawler->filter('meta[name="description"]')->attr('content'),
        );
    }

    public function testUpcomingEditionMetaDescriptionDoesNotPromiseResultsFirst(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::UPCOMING_EDITION_URL);

        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith(
            'EJJ #69 — May 2026 — Euro Jigsaw Jam speed puzzling competition on ',
            (string) $crawler->filter('meta[name="description"]')->attr('content'),
        );
    }

    public function testEditionOfApprovedSeriesIsIndexable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="index, follow"]');
    }

    public function testEditionOfUnapprovedSeriesIsNotIndexable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/series/pending-puzzle-league/pending-puzzle-league-1');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        $this->assertSelectorNotExists('meta[name="robots"][content="index, follow"]');
    }

    public function testEditionOfRejectedSeriesIsNotIndexable(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $crawler = $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertStringNotContainsString('"@type": "Event"', (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('meta[name="robots"]'));
    }

    public function testRejectedEditionIsNotIndexable(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_68],
        );

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testEditionJsonLdNamesTheSeriesAndUsesTheStrippedMediumLogo(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_series SET logo = 'ejj-logo.png' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        preg_match('/<script type="application\/ld\+json">\s*(\{\s*"@context": "https:\/\/schema.org",\s*"@type": "Event".*?)<\/script>/s', $content, $match);
        self::assertArrayHasKey(1, $match, 'The edition page emits Event JSON-LD');
        $event = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($event);
        self::assertSame('Euro Jigsaw Jam · EJJ #68 — February 2026', $event['name'] ?? null);
        self::assertIsString($event['image'] ?? null);
        // The large stripped preset, never the uploaded original (may carry EXIF/GPS)
        self::assertStringEndsWith('/preset:puzzle_large/plain/ejj-logo.png', $event['image']);
    }

    /**
     * No fixture time is linked to EJJ #68 - one result makes it an edition with results here.
     */
    private static function linkResultToThePastEdition(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :editionId WHERE id = :timeId',
            ['editionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => PuzzleSolvingTimeFixture::TIME_01],
        );
    }
}
