<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The add-time page costs what it cost before series became one entry in the "Competition / event" picker
 * (docs/features/events-page/high-frequency-series.md "The 97 % path"): measured on the foundation commit before the
 * picker changed and pinned exactly - a higher number is a bug to explain, not a new number. The picker is one
 * statement, the preview is fetched only once a series is chosen. The second request is counted.
 */
final class PuzzleAddPageQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function providePages(): iterable
    {
        // Measured on the foundation commit 387125b6 (2026-10-09) - the picker changed after that, the numbers did not
        yield 'add time' => ['/en/puzzle-add', 7];
        yield 'add time of a puzzle' => ['/en/puzzle-add/' . PuzzleFixture::PUZZLE_500_01, 10];
        yield 'add time, relax' => ['/en/puzzle-add?mode=relax', 7];
        yield 'add time, collection' => ['/en/puzzle-add?mode=collection', 7];
        yield 'stopwatch finish' => ['/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED, 11];
        // The deep link's one statement (visibility, and whether it is an edition)
        yield 'add time from a one-time event page' => ['/en/puzzle-add?competition=' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, 8];
        yield 'add time from an edition page' => ['/en/puzzle-add?competition=' . EventsPageFixture::EDITION_SPRINT_SEASON, 8];
        // New with series picks (P27, the series page's "Add my time"): the series' visibility statement, like the above
        yield 'add time from a series page' => ['/en/puzzle-add?series=' . EventsPageFixture::SERIES_HARBOR_NIGHTS, 8];
    }

    #[DataProvider('providePages')]
    public function testThePageCost(string $url, int $statements): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertSame($statements, $this->measure($browser, $url), $url);
    }

    /**
     * docs/features/events-page/high-frequency-series.md "Performance": a save with a series pick costs at most three
     * statements more than one with a one-time event (the series, the match, the edition); an explicitly picked edition
     * at most one more (its validation)
     */
    public function testASaveCostsAtMostThreeMoreForASeriesPickAndOneMoreForAnEdition(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());
        $today = self::getContainer()->get(ClockInterface::class)->now();

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $matched = $scenario->edition($seriesId, 'Jam No. 154', $today->modify('-2 days')->format('Y-m-d'));
        $explicit = $scenario->edition($seriesId, 'Jam No. 140', $today->modify('-40 days')->format('Y-m-d'));

        [$oneTime] = $this->measureSave($browser, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, $today->modify('-30 days'), '11');
        [$seriesPick, $seriesTimeId] = $this->measureSave($browser, 'series:' . $seriesId, $today->modify('-2 days'), '12');
        [$edition, $editionTimeId] = $this->measureSave($browser, 'edition:' . $explicit, $today->modify('-40 days'), '13');

        // What was measured: a matched series pick (its edition loaded) and an explicit edition
        self::assertSame($matched, $scenario->link($seriesTimeId)['competition_id']);
        self::assertSame($seriesId, $scenario->link($seriesTimeId)['competition_series_id']);
        self::assertSame($explicit, $scenario->link($editionTimeId)['competition_id']);

        self::assertLessThanOrEqual($oneTime + 3, $seriesPick, 'series pick');
        self::assertLessThanOrEqual($oneTime + 1, $edition, 'explicit edition');
    }

    /**
     * @return array{int, string} the statements of the save and the saved time's id
     */
    private function measureSave(KernelBrowser $browser, string $competition, DateTimeImmutable $day, string $minutes): array
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $token = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($token);
        $timeId = Uuid::uuid7()->toString();

        $this->startCountingQueries($browser);
        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => [
                '_token' => $token,
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                'puzzle' => PuzzleFixture::PUZZLE_500_01,
                'timeHours' => '1',
                'timeMinutes' => $minutes,
                'timeSeconds' => '0',
                'finishedAt' => $day->format('d.m.Y'),
                'competition' => $competition,
                'collection' => '__system_collection__',
            ],
            'time_id' => $timeId,
        ]);
        self::assertResponseRedirects('/en/time-added/' . $timeId);

        return [$this->queryCount($browser), $timeId];
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
