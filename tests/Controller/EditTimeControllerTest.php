<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class EditTimeControllerTest extends WebTestCase
{
    // TIME_06: PLAYER_REGULAR on PUZZLE_500_02, 36:40, no competition
    private const string TIME_ID = PuzzleSolvingTimeFixture::TIME_06;
    private const string EDIT_URL = '/en/edit-time/' . self::TIME_ID;

    public function testAnonymousUserIsRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::EDIT_URL);

        $this->assertResponseRedirects();
    }

    public function testOtherPlayersTimeIsForbidden(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', self::EDIT_URL);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testGroupMemberWhoDidNotTrackTheTimeCanEditButNotDelete(): void
    {
        // TIME_12 is a duo tracked by PLAYER_REGULAR - PLAYER_PRIVATE is the partner
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12);

        $this->assertResponseIsSuccessful();
        // Deleting stays with whoever tracked the time
        self::assertStringNotContainsString('/en/delete-time/', (string) $browser->getResponse()->getContent());
    }

    public function testTrackerIsStillOfferedDelete(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('/en/delete-time/', (string) $browser->getResponse()->getContent());
    }

    public function testPlayerOutsideTheGroupIsForbidden(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12);

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * H12 scenario 12: an explicitly linked edition shows as `edition:<uuid>`, is offered, and a re-save keeps it explicit
     */
    public function testScenario12AnExplicitEditionIsShownAndSurvivesResave(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $timeId = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_02, $this->day(-2), competitionId: $editionId);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        $this->assertResponseIsSuccessful();

        self::assertSame('edition:' . $editionId, $this->competitionValue($crawler));
        self::assertStringContainsString('"edition:' . $editionId . '"', $this->tomSelectOptions($crawler));

        $browser->request('POST', '/en/edit-time/' . $timeId, [
            'edit_puzzle_solving_time_form' => $this->submissionFor($crawler, 'edition:' . $editionId, PuzzleFixture::PUZZLE_500_02, $this->day(-2, 'd.m.Y')),
        ]);

        $this->assertResponseRedirects();
        $link = $scenario->link($timeId);
        self::assertSame($editionId, $link['competition_id']);
        self::assertNull($link['competition_series_id']);
    }

    /**
     * H12 scenario 12: a series pick shows as the series (the edition it was matched to is only named by the preview)
     */
    public function testScenario12ASeriesPickIsShownAsTheSeries(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $timeId = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_02, $this->day(-2), seriesId: $seriesId);
        self::assertSame($editionId, $scenario->link($timeId)['competition_id']);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        $this->assertResponseIsSuccessful();

        self::assertSame('series:' . $seriesId, $this->competitionValue($crawler));
        self::assertStringNotContainsString('edition:', $this->tomSelectOptions($crawler));
    }

    /**
     * H12 scenario 12: the time's series pick stays offered and saved when the series is no longer public; so does an
     * explicit edition that is no longer public (include-current)
     */
    public function testScenario12TheCurrentSeriesOrEditionIsKeptWhenNoLongerPublic(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $database = self::getContainer()->get(Connection::class);

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $pickTime = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_02, $this->day(-2), seriesId: $seriesId);

        $otherSeries = $scenario->series('Moonlit Puzzle Sprints');
        $editionId = $scenario->edition($otherSeries, 'Sprint 1', $this->day(-5));
        $explicitTime = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_03, $this->day(-5), competitionId: $editionId);

        $database->executeStatement('UPDATE competition_series SET is_draft = true WHERE id IN (:a, :b)', ['a' => $seriesId, 'b' => $otherSeries]);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-time/' . $pickTime);
        self::assertSame('series:' . $seriesId, $this->competitionValue($crawler));
        self::assertSame(1, substr_count($this->tomSelectOptions($crawler), '"series:' . $seriesId . '"'));

        $browser->request('POST', '/en/edit-time/' . $pickTime, [
            'edit_puzzle_solving_time_form' => $this->submissionFor($crawler, 'series:' . $seriesId, PuzzleFixture::PUZZLE_500_02, $this->day(-2, 'd.m.Y')),
        ]);
        $this->assertResponseRedirects();
        self::assertSame($seriesId, $scenario->link($pickTime)['competition_series_id']);

        $crawler = $browser->request('GET', '/en/edit-time/' . $explicitTime);
        self::assertSame('edition:' . $editionId, $this->competitionValue($crawler));
        self::assertStringContainsString('"edition:' . $editionId . '"', $this->tomSelectOptions($crawler));

        $browser->request('POST', '/en/edit-time/' . $explicitTime, [
            'edit_puzzle_solving_time_form' => $this->submissionFor($crawler, 'edition:' . $editionId, PuzzleFixture::PUZZLE_500_03, $this->day(-5, 'd.m.Y')),
        ]);
        $this->assertResponseRedirects();
        self::assertSame($editionId, $scenario->link($explicitTime)['competition_id']);
    }

    /**
     * H12 scenario 12: another puzzle re-resolves a series pick - an explicit link stays as it is
     */
    public function testScenario12AnotherPuzzleReResolvesOnlyASeriesPick(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 140', $this->day(-40));
        $scenario->round($editionId, RoundCategory::Solo, $this->day(-40) . ' 19:00', puzzleIds: [PuzzleFixture::PUZZLE_500_02]);

        // Matched by its puzzle, far from the edition's day
        $pickTime = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_02, $this->day(-3), seriesId: $seriesId);
        self::assertSame('puzzle', $scenario->link($pickTime)['series_edition_match']);
        $explicitTime = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_02, $this->day(-4), competitionId: $editionId, time: '01:09:00');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-time/' . $pickTime);
        $browser->request('POST', '/en/edit-time/' . $pickTime, [
            'edit_puzzle_solving_time_form' => $this->submissionFor($crawler, 'series:' . $seriesId, PuzzleFixture::PUZZLE_500_03, $this->day(-3, 'd.m.Y')),
        ]);
        $this->assertResponseRedirects();
        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $seriesId, 'series_edition_match' => null, 'competition_round_id' => null],
            $scenario->link($pickTime),
        );

        $crawler = $browser->request('GET', '/en/edit-time/' . $explicitTime);
        $browser->request('POST', '/en/edit-time/' . $explicitTime, [
            'edit_puzzle_solving_time_form' => $this->submissionFor($crawler, 'edition:' . $editionId, PuzzleFixture::PUZZLE_500_03, $this->day(-4, 'd.m.Y'), minutes: '9'),
        ]);
        $this->assertResponseRedirects();
        self::assertSame($editionId, $scenario->link($explicitTime)['competition_id']);
        self::assertNull($scenario->link($explicitTime)['competition_series_id']);
    }

    public function testSwitchingAnExplicitEditionToItsSeriesMakesItASeriesPick(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $timeId = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_02, $this->day(-2), competitionId: $editionId);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        $browser->request('POST', '/en/edit-time/' . $timeId, [
            'edit_puzzle_solving_time_form' => $this->submissionFor($crawler, 'series:' . $seriesId, PuzzleFixture::PUZZLE_500_02, $this->day(-2, 'd.m.Y')),
        ]);

        $this->assertResponseRedirects();
        self::assertSame(
            ['competition_id' => $editionId, 'competition_series_id' => $seriesId, 'series_edition_match' => 'date', 'competition_round_id' => null],
            $scenario->link($timeId),
        );
    }

    public function testCurrentlyLinkedNotPubliclySelectableCompetitionIsKeptOnResave(): void
    {
        // The link predates an approval decision (or the event got rejected later) - the picker must
        // still offer it, otherwise the control renders empty and a plain re-save detaches the time
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $this->linkTimeTo($database, CompetitionFixture::COMPETITION_UNAPPROVED);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $tomSelectOptions = $crawler
            ->filter('#edit_puzzle_solving_time_form_competition')
            ->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($tomSelectOptions);
        self::assertStringContainsString(CompetitionFixture::COMPETITION_UNAPPROVED, $tomSelectOptions);
        self::assertSame(1, substr_count($tomSelectOptions, CompetitionFixture::COMPETITION_UNAPPROVED));

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->submission($crawler, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseRedirects();
        self::assertSame(CompetitionFixture::COMPETITION_UNAPPROVED, $this->linkedCompetitionId($database));
    }

    public function testSwitchingToANotSelectableCompetitionIsRejected(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $tomSelectOptions = $crawler
            ->filter('#edit_puzzle_solving_time_form_competition')
            ->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($tomSelectOptions);
        self::assertStringNotContainsString(CompetitionFixture::COMPETITION_UNAPPROVED, $tomSelectOptions);

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->submission($crawler, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="edit_puzzle_solving_time_form"]', "This competition or event can't be selected");
        self::assertNull($this->linkedCompetitionId($database));
    }

    public function testSwitchingToASeriesEditionLinksTheTime(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->submission($crawler, CompetitionSeriesFixture::EDITION_OFFLINE_1),
        ]);

        $this->assertResponseRedirects();
        self::assertSame(CompetitionSeriesFixture::EDITION_OFFLINE_1, $this->linkedCompetitionId($database));
    }

    /**
     * An empty co-puzzler row used to fall through to a 200 re-render of a valid form, which Turbo Drive
     * discards on the full-page edit form - the visitor saw nothing happen.
     */
    public function testEmptyCoPuzzlerIsRejectedWithVisibleError(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $submission = $this->submission($crawler, CompetitionSeriesFixture::EDITION_OFFLINE_1);

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $submission,
            'group_players' => [''],
        ], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="edit_puzzle_solving_time_form"]', 'One of the puzzlers is empty');
        self::assertNull($this->linkedCompetitionId($database));
    }

    /**
     * @return array<string, string>
     */
    private function submission(Crawler $crawler, string $competitionId): array
    {
        $csrfToken = $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        return [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_02,
            'timeHours' => '0',
            'timeMinutes' => '36',
            'timeSeconds' => '40',
            'finishedAt' => '12.07.2026',
            'competition' => $competitionId,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function submissionFor(Crawler $crawler, string $competition, string $puzzleId, string $finishedAt, string $minutes = '5'): array
    {
        // The time SeriesEditionScenario::addTime() saved (1:05:00) - only the link, the puzzle or the day change
        return [
            ...$this->submission($crawler, $competition),
            'puzzle' => $puzzleId,
            'finishedAt' => $finishedAt,
            'timeHours' => '1',
            'timeMinutes' => $minutes,
            'timeSeconds' => '0',
        ];
    }

    private function competitionValue(Crawler $crawler): string
    {
        return (string) $crawler->filter('#edit_puzzle_solving_time_form_competition')->attr('value');
    }

    private function tomSelectOptions(Crawler $crawler): string
    {
        $options = $crawler
            ->filter('#edit_puzzle_solving_time_form_competition')
            ->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($options);

        return $options;
    }

    private function day(int $offset, string $format = 'Y-m-d'): string
    {
        return self::getContainer()->get(ClockInterface::class)->now()->modify(sprintf('%+d days', $offset))->format($format);
    }

    private function linkTimeTo(Connection $database, string $competitionId): void
    {
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :id',
            ['competitionId' => $competitionId, 'id' => self::TIME_ID],
        );
    }

    private function linkedCompetitionId(Connection $database): null|string
    {
        /** @var null|string|false $competitionId */
        $competitionId = $database->fetchOne(
            'SELECT competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => self::TIME_ID],
        );

        return $competitionId === false ? null : $competitionId;
    }
}
