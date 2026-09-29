<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class EventDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
    }

    public function testAddMyTimeLinkIsShownToLoggedInPlayerOnStartedEvent(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Euro Jigsaw Jam is approved and live today
        $browser->request('GET', '/en/events/euro-jigsaw-jam');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_RECURRING_ONLINE));
    }

    public function testAddMyTimeLinkIsHiddenFromAnonymousVisitor(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/euro-jigsaw-jam');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_RECURRING_ONLINE));
    }

    public function testAddMyTimeLinkIsHiddenOnUpcomingEvent(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // WJPC 2024 starts in 30 days
        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testMemberSeesDifficultyOfEventPuzzles(): void
    {
        $browser = self::createClient();

        // Event puzzles are the ones carrying the event's tag - no fixture puzzle has one
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );
        $connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, computed_at)
             VALUES (:puzzleId, 1.4, :tier, 'high', 30, NOW())
             ON CONFLICT (puzzle_id) DO UPDATE SET difficulty_tier = EXCLUDED.difficulty_tier",
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01, 'tier' => DifficultyTier::Hard->value],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('#puzzle-list-item-%s use[href$="#diff-hard"]', PuzzleFixture::PUZZLE_500_01));
    }

    public function testEventPuzzlesShowTheirRoundLatestFirst(): void
    {
        $browser = self::createClient();

        // Final Round (+32 days) puzzle is tagged first, Qualification Round (+30 days) puzzle second -
        // the page lists the latest round first, whatever the tagging order
        $connection = self::getContainer()->get(Connection::class);
        foreach ([PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_500_01] as $puzzleId) {
            $connection->executeStatement(
                'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
                ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => $puzzleId],
            );
        }

        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01, 'Qualification Round');
        $this->assertSelectorTextContains('#puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_01, 'Final Round');
        // Each round badge links to that round's results
        $this->assertSelectorExists('#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01 . ' a[href="/en/events/wjpc-2024/results/qualification-round"]');

        $order = $crawler->filter('[id^="puzzle-list-item-"]')->each(
            static fn ($item): string => (string) $item->attr('id'),
        );
        self::assertSame([
            'puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_01,
            'puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01,
        ], $order);
    }

    public function testRoundChipsAreShownWhenParticipantsAreAssignedToRounds(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-live-round-id-param="' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION . '"]');
    }

    public function testRoundChipsAreHiddenWhenNobodyIsAssignedToARound(): void
    {
        $browser = self::createClient();

        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM competition_participant_round');

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-live-round-id-param]');
    }

    public function testUpcomingEventTitleIsItsFullName(): void
    {
        $browser = self::createClient();

        // WJPC 2024 starts in 30 days and its name carries the year
        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        self::assertSame('WJPC 2024 – MySpeedPuzzling', $crawler->filter('title')->text());
        // Upcoming events keep date + location in the meta description
        self::assertStringContainsString('in Prague', self::metaDescription($crawler));
    }

    public function testUpcomingEventWithoutYearInNameGetsTheYear(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition SET name = 'Czech National Championship' WHERE id = :id",
            ['id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        );

        $crawler = $browser->request('GET', '/en/events/czech-nationals-2024');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            sprintf('Czech National Championship %s – MySpeedPuzzling', self::startYear(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024)),
            $crawler->filter('title')->text(),
        );
        // The heading stays the organiser's name
        self::assertSame('Czech National Championship', trim($crawler->filter('h1')->text()));
    }

    public function testPastEventTitleSaysResults(): void
    {
        $browser = self::createClient();
        self::moveToThePast(CompetitionFixture::COMPETITION_WJPC_2024);

        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        self::assertSame('WJPC 2024 Results – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertSame('WJPC 2024', trim($crawler->filter('h1')->text()));
    }

    public function testPastEventWithoutYearInNameGetsTheYearAndResults(): void
    {
        $browser = self::createClient();
        self::moveToThePast(CompetitionFixture::COMPETITION_WJPC_2024);
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET name = 'Puzzle Marathon' WHERE id = :id",
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            sprintf('Puzzle Marathon %s Results – MySpeedPuzzling', self::startYear(CompetitionFixture::COMPETITION_WJPC_2024)),
            $crawler->filter('title')->text(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePastEventTitleInEveryLocale(): iterable
    {
        yield 'en' => ['/en/events/wjpc-2024', 'WJPC 2024 Results – MySpeedPuzzling'];
        yield 'cs' => ['/eventy/wjpc-2024', 'WJPC 2024 – výsledky – MySpeedPuzzling'];
        yield 'de' => ['/de/veranstaltungen/wjpc-2024', 'WJPC 2024 Ergebnisse – MySpeedPuzzling'];
        yield 'es' => ['/es/eventos/wjpc-2024', 'Resultados WJPC 2024 – MySpeedPuzzling'];
        yield 'fr' => ['/fr/evenements/wjpc-2024', 'Résultats WJPC 2024 – MySpeedPuzzling'];
        yield 'ja' => ['/ja/イベント/wjpc-2024', 'WJPC 2024 結果 – MySpeedPuzzling'];
    }

    #[DataProvider('providePastEventTitleInEveryLocale')]
    public function testPastEventTitleIsLocalized(string $url, string $expectedTitle): void
    {
        $browser = self::createClient();
        self::moveToThePast(CompetitionFixture::COMPETITION_WJPC_2024);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertSame($expectedTitle, $crawler->filter('title')->text());
    }

    public function testUndatedEventTitleIsJustItsName(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET date_from = NULL, date_to = NULL WHERE id = :id',
            ['id' => CompetitionFixture::COMPETITION_RECURRING_ONLINE],
        );

        $crawler = $browser->request('GET', '/en/events/euro-jigsaw-jam');

        $this->assertResponseIsSuccessful();
        self::assertSame('Euro Jigsaw Jam – MySpeedPuzzling', $crawler->filter('title')->text());
    }

    public function testPastEventMetaDescriptionSaysHowManyResultsThereAre(): void
    {
        $browser = self::createClient();
        self::moveToThePast(CompetitionFixture::COMPETITION_WJPC_2024);
        $resultsCount = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_id = :id AND suspicious = false',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );
        self::assertIsNumeric($resultsCount);
        self::assertGreaterThan(1, (int) $resultsCount, 'Fixtures link several WJPC 2024 times');

        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $description = self::metaDescription($crawler);
        self::assertStringStartsWith('WJPC 2024 results: ', $description);
        self::assertStringContainsString(sprintf('%d times added by puzzlers', (int) $resultsCount), $description);
        self::assertStringContainsString('in Prague', $description);
    }

    public function testPastEventWithoutResultsKeepsTheGeneralDescription(): void
    {
        $browser = self::createClient();
        self::moveToThePast(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);

        $crawler = $browser->request('GET', '/en/events/czech-nationals-2024');

        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith('Czech National Championship 2024 — speed puzzling competition on ', self::metaDescription($crawler));
    }

    public function testEventJsonLdImageIsTheOriginalLogo(): void
    {
        $browser = self::createClient();

        // Approved, running, with a logo
        $browser->request('GET', '/en/events/api-reveal-test-competition');

        $this->assertResponseIsSuccessful();
        $event = self::jsonLdOfType((string) $browser->getResponse()->getContent(), 'Event');
        self::assertIsString($event['image'] ?? null);
        self::assertStringEndsWith('/original/api-reveal-logo.png', $event['image']);
    }

    public function testEventWithoutTaggedPuzzlesListsThePuzzlesOfItsRounds(): void
    {
        $browser = self::createClient();

        // No fixture puzzle carries the WJPC tag - the qualification and final rounds have two puzzles each
        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $order = $crawler->filter('[id^="puzzle-list-item-"]')->each(
            static fn (Crawler $item): string => (string) $item->attr('id'),
        );
        self::assertCount(4, $order);
        // Latest round first, as for tagged puzzles
        self::assertEqualsCanonicalizing(
            ['puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_01, 'puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_02],
            array_slice($order, 0, 2),
        );
        self::assertEqualsCanonicalizing(
            ['puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01, 'puzzle-list-item-' . PuzzleFixture::PUZZLE_500_02],
            array_slice($order, 2, 2),
        );
        $this->assertSelectorTextContains('#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01, 'Qualification Round');
        $this->assertSelectorTextNotContains('main', 'No puzzles here');
    }

    public function testEventWithoutAnyPuzzlesSaysSo(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/unapproved-puzzle-event');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[id^="puzzle-list-item-"]');
        $this->assertSelectorTextContains('main', 'No puzzles here');
    }

    public function testResultsByRoundLinksEveryRoundWithResults(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['/en/events/wjpc-2024/results/qualification-round', '/en/events/wjpc-2024/results/final-round'],
            $crawler->filter('[data-event-round-results] a')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        $this->assertSelectorTextContains('[data-event-round-results] a', 'Qualification Round');
    }

    public function testRoundWithoutResultsIsNotListed(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = NULL WHERE competition_round_id = :roundId',
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );

        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['/en/events/wjpc-2024/results/qualification-round'],
            $crawler->filter('[data-event-round-results] a')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
    }

    public function testEventThatIsNotPublicLinksNoRoundResults(): void
    {
        $browser = self::createClient();
        // Its round results pages answer 404
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET approved_at = NULL WHERE id = :id',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-event-round-results]');
        $this->assertSelectorNotExists('a[href^="/en/events/wjpc-2024/results/"]');
    }

    private static function addTimeLinkSelector(string $competitionId): string
    {
        return sprintf('a[href$="?competition=%s"]', $competitionId);
    }

    private static function moveToThePast(string $competitionId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET date_from = NOW() - INTERVAL '10 days', date_to = NOW() - INTERVAL '8 days' WHERE id = :id",
            ['id' => $competitionId],
        );
    }

    private static function startYear(string $competitionId): string
    {
        $dateFrom = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT date_from FROM competition WHERE id = :id',
            ['id' => $competitionId],
        );
        self::assertIsString($dateFrom);

        return new DateTimeImmutable($dateFrom)->format('Y');
    }

    private static function metaDescription(Crawler $crawler): string
    {
        return (string) $crawler->filter('meta[name="description"]')->attr('content');
    }

    /**
     * @return array<mixed>
     */
    private static function jsonLdOfType(string $html, string $type): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === $type) {
                return $decoded;
            }
        }

        self::fail(sprintf('No %s JSON-LD on the page', $type));
    }
}
