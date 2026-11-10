<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The "Competition / event" list on a submit: a form that comes back shows what the page showed, a value it may not take
 * is refused with the one generic error (docs/features/competitions-management/README.md "Linking solving times to
 * events").
 */
final class CompetitionPickerOnSaveTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string ADD_URL = '/en/puzzle-add';
    // TIME_06: PLAYER_REGULAR on PUZZLE_500_02, no competition
    private const string EDIT_URL = '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_06;
    private const string NOT_SELECTABLE = "This competition or event can't be selected";

    public function testARefusedAddFormOffersExactlyWhatThePageOffered(): void
    {
        $browser = $this->signedIn();
        $page = $browser->request('GET', self::ADD_URL);
        $offered = $this->addFormOptions($page);
        self::assertContains(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, array_column($this->decoded($offered)['options'], 'value'));

        $submission = $this->addSubmission($page, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND);
        $submission['timeHours'] = '0';
        $submission['timeMinutes'] = '0';

        $refused = $browser->request('POST', self::ADD_URL, ['puzzle_add_form' => $submission]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame($offered, $this->addFormOptions($refused));
        self::assertSame(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, $refused->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
    }

    public function testARefusedValueComesBackWithTheGenericErrorAndTheWholeList(): void
    {
        $browser = $this->signedIn();
        $page = $browser->request('GET', self::ADD_URL);
        $offered = $this->addFormOptions($page);

        $refused = $browser->request('POST', self::ADD_URL, [
            'puzzle_add_form' => $this->addSubmission($page, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', self::NOT_SELECTABLE);
        self::assertSame($offered, $this->addFormOptions($refused));
    }

    public function testAValueInASpellingTheListNeverOfferedIsRefused(): void
    {
        $browser = $this->signedIn();
        $page = $browser->request('GET', self::ADD_URL);

        $browser->request('POST', self::ADD_URL, [
            'puzzle_add_form' => $this->addSubmission($page, '{' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND . '}'),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', self::NOT_SELECTABLE);
    }

    public function testARefusedEditFormStillOffersTheNoLongerPublicLink(): void
    {
        $browser = $this->signedIn();
        $this->linkTimeTo(CompetitionFixture::COMPETITION_UNAPPROVED);

        $page = $browser->request('GET', self::EDIT_URL);
        $offered = $this->editFormOptions($page);
        self::assertContains(CompetitionFixture::COMPETITION_UNAPPROVED, array_column($this->decoded($offered)['options'], 'value'));

        $submission = $this->editSubmission($page, CompetitionFixture::COMPETITION_UNAPPROVED);
        $submission['timeHours'] = '0';
        $submission['timeMinutes'] = '0';
        $submission['timeSeconds'] = '0';

        $refused = $browser->request('POST', self::EDIT_URL, ['edit_puzzle_solving_time_form' => $submission]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame($offered, $this->editFormOptions($refused));
        self::assertStringNotContainsString(self::NOT_SELECTABLE, (string) $browser->getResponse()->getContent());
    }

    public function testAValidAddDoesNotBuildTheList(): void
    {
        $browser = $this->signedIn();
        $page = $browser->request('GET', self::ADD_URL);
        $timeId = Uuid::uuid7()->toString();

        $this->startCountingQueries($browser);
        $browser->request('POST', self::ADD_URL, [
            'puzzle_add_form' => $this->addSubmission($page, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND),
            'time_id' => $timeId,
        ]);

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(0, $this->listStatements($browser), 'A save that only redirects has no use for the list');
    }

    public function testAValidAddWithASeriesPickOrAnEditionDoesNotBuildTheList(): void
    {
        $browser = $this->signedIn();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $today = self::getContainer()->get(ClockInterface::class)->now();

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $edition = $scenario->edition($seriesId, 'Jam No. 154', $today->modify('-40 days')->format('Y-m-d'));

        foreach (['series:' . $seriesId => $today->modify('-3 days'), 'edition:' . $edition => $today->modify('-40 days')] as $value => $day) {
            $page = $browser->request('GET', self::ADD_URL);
            $submission = $this->addSubmission($page, $value);
            $submission['finishedAt'] = $day->format('d.m.Y');
            $timeId = Uuid::uuid7()->toString();

            $this->startCountingQueries($browser);
            $browser->request('POST', self::ADD_URL, ['puzzle_add_form' => $submission, 'time_id' => $timeId]);

            self::assertResponseRedirects('/en/time-added/' . $timeId);
            self::assertSame(0, $this->listStatements($browser), $value);
        }
    }

    public function testAValidEditDoesNotBuildTheList(): void
    {
        $browser = $this->signedIn();
        $page = $browser->request('GET', self::EDIT_URL);

        $this->startCountingQueries($browser);
        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->editSubmission($page, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND),
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, $this->listStatements($browser));
    }

    public function testAValidStopwatchFinishDoesNotBuildTheList(): void
    {
        $browser = $this->signedIn();
        $url = '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED;
        $page = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $timeId = Uuid::uuid7()->toString();

        $submission = $this->addSubmission($page, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND);

        $this->startCountingQueries($browser);
        $browser->request('POST', $url, ['puzzle_add_form' => $submission, 'time_id' => $timeId]);

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(0, $this->listStatements($browser));
    }

    public function testAFormThatComesBackBuildsTheListOnce(): void
    {
        $browser = $this->signedIn();
        $page = $browser->request('GET', self::ADD_URL);

        $this->startCountingQueries($browser);
        $browser->request('POST', self::ADD_URL, [
            'puzzle_add_form' => $this->addSubmission($page, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->listStatements($browser));
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    private function listStatements(KernelBrowser $browser): int
    {
        return count(array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'SELECT s.kind, s.id, s.name'),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function addSubmission(Crawler $page, string $competition): array
    {
        $token = $page->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        return [
            '_token' => $token,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => self::getContainer()->get(ClockInterface::class)->now()->modify('-30 days')->format('d.m.Y'),
            'competition' => $competition,
            'collection' => '__system_collection__',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function editSubmission(Crawler $page, string $competition): array
    {
        $token = $page->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        return [
            '_token' => $token,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_02,
            'timeHours' => '0',
            'timeMinutes' => '36',
            'timeSeconds' => '40',
            'finishedAt' => self::getContainer()->get(ClockInterface::class)->now()->modify('-30 days')->format('d.m.Y'),
            'competition' => $competition,
        ];
    }

    private function addFormOptions(Crawler $page): string
    {
        return $this->tomSelectOptions($page, '#puzzle_add_form_competition');
    }

    private function editFormOptions(Crawler $page): string
    {
        return $this->tomSelectOptions($page, '#edit_puzzle_solving_time_form_competition');
    }

    private function tomSelectOptions(Crawler $page, string $selector): string
    {
        return (string) $page->filter($selector)->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
    }

    /**
     * @return array{options: list<array{value: string}>}
     */
    private function decoded(string $json): array
    {
        /** @var array{options: list<array{value: string}>} $options */
        $options = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $options;
    }

    private function linkTimeTo(string $competitionId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :id',
            ['competitionId' => $competitionId, 'id' => PuzzleSolvingTimeFixture::TIME_06],
        );
    }
}
