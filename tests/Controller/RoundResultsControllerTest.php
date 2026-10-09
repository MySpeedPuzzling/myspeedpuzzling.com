<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoundResultsControllerTest extends WebTestCase
{
    private const string QUALIFICATION_URL = '/en/events/wjpc-2024/results/qualification-round';

    public function testUpcomingRoundSaysWhenItStarts(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::QUALIFICATION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'This round starts');
        $this->assertSelectorNotExists('[data-round-result-status]');
    }

    public function testStartedRoundRanksItsResults(): void
    {
        $browser = self::createClient();
        $this->startQualificationRound($browser);

        $crawler = $browser->request('GET', self::QUALIFICATION_URL);

        $this->assertResponseIsSuccessful();
        // Three fixture results, the private player's is hidden from anonymous visitors
        self::assertCount(2, $crawler->filter('[data-round-result-status="finished"]'));
        // Only puzzlers who added a time are listed - no positions that could pass for official placings
        $this->assertSelectorNotExists('td.rank');
        $this->assertSelectorTextContains('[data-round-unofficial]', 'Times logged on MySpeedPuzzling - not the official results');
        $this->assertSelectorTextContains('title', 'Qualification Round');
    }

    public function testLocalizedUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/eventy/wjpc-2024/vysledky/qualification-round');

        $this->assertResponseIsSuccessful();
    }

    public function testUnknownRoundIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/wjpc-2024/results/no-such-round');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRoundOfACompetitionThatIsNotPublicIsNotFound(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, category, slug) VALUES (:id, :competition, 'Hidden', 60, NOW() - INTERVAL '1 day', 'solo', 'hidden')",
            ['id' => Uuid::uuid7()->toString(), 'competition' => CompetitionFixture::COMPETITION_UNAPPROVED],
        );

        $browser->request('GET', '/en/events/unapproved-puzzle-event/results/hidden');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRoundNavigationAndOfficialResultsLink(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET results_link = 'https://example.com/wjpc/qualification' WHERE id = :id",
            ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        );

        $browser->request('GET', self::QUALIFICATION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/en/events/wjpc-2024/results/final-round"]');
        $this->assertSelectorExists('a[href="https://example.com/wjpc/qualification?utm_source=myspeedpuzzling"]');
    }

    public function testAddMyTimeOnlyForSignedInPlayersOnceTheRoundStarted(): void
    {
        $browser = self::createClient();
        $this->startQualificationRound($browser);
        $addTimeLink = sprintf('a[href$="/%s?competition=%s"]', PuzzleFixture::PUZZLE_500_01, CompetitionFixture::COMPETITION_WJPC_2024);

        $browser->request('GET', self::QUALIFICATION_URL);
        $this->assertSelectorNotExists($addTimeLink);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::QUALIFICATION_URL);
        $this->assertSelectorExists($addTimeLink);
    }

    public function testEditionRoundResults(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round');

        $this->assertResponseIsSuccessful();
    }

    public function testTitleLeadsWithTheEvent(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::QUALIFICATION_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame('WJPC 2024 – Qualification Round Results – MySpeedPuzzling', $crawler->filter('title')->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalizedTitles(): iterable
    {
        yield 'cs' => ['/eventy/wjpc-2024/vysledky/qualification-round', 'WJPC 2024 – Qualification Round – výsledky – MySpeedPuzzling'];
        yield 'de' => ['/de/veranstaltungen/wjpc-2024/ergebnisse/qualification-round', 'WJPC 2024 – Qualification Round Ergebnisse – MySpeedPuzzling'];
        yield 'es' => ['/es/eventos/wjpc-2024/resultados/qualification-round', 'WJPC 2024 – Resultados Qualification Round – MySpeedPuzzling'];
        yield 'fr' => ['/fr/evenements/wjpc-2024/resultats/qualification-round', 'WJPC 2024 – Résultats Qualification Round – MySpeedPuzzling'];
        yield 'ja' => ['/ja/イベント/wjpc-2024/results/qualification-round', 'WJPC 2024 – Qualification Round 結果 – MySpeedPuzzling'];
    }

    #[DataProvider('provideLocalizedTitles')]
    public function testTitleIsLocalized(string $url, string $expectedTitle): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertSame($expectedTitle, $crawler->filter('title')->text());
    }

    public function testEditionRoundTitleNamesTheSeries(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET name = 'Main Round' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::ROUND_EJJ_68],
        );

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round');

        $this->assertResponseIsSuccessful();
        self::assertSame('Euro Jigsaw Jam · EJJ #68 — February 2026 – Main Round Results – MySpeedPuzzling', $crawler->filter('title')->text());
    }

    public function testRoundNamedLikeItsEditionIsNamedOnce(): void
    {
        $browser = self::createClient();

        // The fixture's only EJJ #68 round is called "EJJ #68 — February 2026" too
        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round');

        $this->assertResponseIsSuccessful();
        self::assertSame('Euro Jigsaw Jam · EJJ #68 — February 2026 Results – MySpeedPuzzling', $crawler->filter('title')->text());
        // The page heading is untouched
        $this->assertSelectorTextContains('h1', 'EJJ #68 — February 2026');
    }

    public function testEditionReachedThroughTheEventRouteRedirects(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/ejj-68-february-2026/results/main-round');

        $this->assertResponseRedirects('/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round', 301);
    }

    /**
     * Whenever the times puzzlers logged are listed, a label says they are not the official results, with the
     * organiser's results right next to it - the round's link, else the edition's, both with utm_source
     * (docs/features/events-page/high-frequency-series.md "Edition page and round results")
     */
    public function testTheTimesAreLabelledUnofficialNextToTheOfficialResults(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $scenario = new SeriesEditionScenario(self::getContainer());
        $day = new DateTimeImmutable('-3 days')->format('Y-m-d');
        $seriesId = $scenario->series();
        $editionId = $scenario->edition($seriesId, 'Jam No. 153', $day);
        $roundId = $scenario->round($editionId, RoundCategory::Solo, $day . ' 19:00', puzzleIds: [$scenario->puzzle()]);
        $path = $connection->fetchOne(
            "SELECT cs.slug || '/' || c.slug || '/results/' || cr.slug FROM competition_round cr
             INNER JOIN competition c ON c.id = cr.competition_id
             INNER JOIN competition_series cs ON cs.id = c.series_id
             WHERE cr.id = :id",
            ['id' => $roundId],
        );
        self::assertIsString($path);
        $url = '/en/series/' . $path;

        // No time logged yet, no results link: the label alone
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('Times logged on MySpeedPuzzling - not the official results', trim($crawler->filter('[data-round-unofficial]')->text()));
        self::assertCount(0, $crawler->filter('[data-round-official-link]'));

        $connection->executeStatement("UPDATE competition SET results_link = 'https://results.example/jam-153' WHERE id = :id", ['id' => $editionId]);
        $crawler = $browser->request('GET', $url);
        $link = $crawler->filter('[data-round-unofficial] [data-round-official-link]');
        self::assertSame('https://results.example/jam-153?utm_source=myspeedpuzzling', $link->attr('href'), 'the edition\'s results');
        self::assertSame('Official results ↗', trim($link->text()));
        // Only there - the header has no button of its own while the label carries the link
        self::assertCount(1, $crawler->filter('a[href="https://results.example/jam-153?utm_source=myspeedpuzzling"]'));

        $connection->executeStatement("UPDATE competition_round SET results_link = 'https://results.example/jam-153/solo?lang=en' WHERE id = :id", ['id' => $roundId]);
        $crawler = $browser->request('GET', $url);
        self::assertSame(
            'https://results.example/jam-153/solo?lang=en&utm_source=myspeedpuzzling',
            $crawler->filter('[data-round-unofficial] [data-round-official-link]')->attr('href'),
            'the round\'s own link wins',
        );
    }

    private function startQualificationRound(KernelBrowser $browser): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET starts_at = NOW() - INTERVAL '2 days' WHERE id = :id",
            ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        );
    }
}
