<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PuzzleAddControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAnonymousUserIsRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessForm(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="puzzle_add_form"]');
    }

    /**
     * @return array<string, array{null|string}>
     */
    public static function provideInvalidPuzzleValues(): array
    {
        return [
            // A disabled input on the client is excluded from the submit entirely
            'puzzle field missing from request' => [null],
            'puzzle field empty' => [''],
        ];
    }

    /**
     * Regression test: submitting without a puzzle must produce a validation
     * error, not a TypeError when constructing the AddPuzzleSolvingTime message.
     */
    #[DataProvider('provideInvalidPuzzleValues')]
    public function testSubmitWithoutPuzzleShowsValidationError(null|string $puzzle): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $formData = [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => '12.07.2026',
            'collection' => '__system_collection__',
        ];

        if ($puzzle !== null) {
            $formData['puzzle'] = $puzzle;
        }

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $formData,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', 'This field is required!');
    }

    /**
     * docs/features/events-page/high-frequency-series.md "The default list": one-time events and series, one option
     * each - no edition baked into the page (H12 scenario 1: one-time events as before)
     */
    public function testCompetitionPickerOffersOneTimeEventsAndSeriesButNoEditions(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $tomSelectOptions = $this->tomSelectOptions($crawler);

        self::assertStringContainsString('"series:' . EventsPageFixture::SERIES_HARBOR_NIGHTS . '"', $tomSelectOptions);
        self::assertStringContainsString(EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME, $tomSelectOptions);
        self::assertStringNotContainsString(EventsPageFixture::EDITION_HARBOR_1, $tomSelectOptions);
        self::assertStringNotContainsString('edition:', $tomSelectOptions);
        self::assertStringContainsString('"' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND . '"', $tomSelectOptions);
        self::assertStringNotContainsString(CompetitionFixture::COMPETITION_UNAPPROVED, $tomSelectOptions);
        self::assertStringNotContainsString(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, $tomSelectOptions);
        self::assertStringNotContainsString(CompetitionSeriesFixture::SERIES_UNAPPROVED, $tomSelectOptions);
        // S1 and the preview are wired, the old "pick the specific edition" hint is gone (P31)
        self::assertCount(1, $crawler->filter('[data-competition-picker-editions-url-value="/en/competition-picker/editions"]'));
        self::assertCount(1, $crawler->filter('[data-series-edition-preview-url-value="/en/competition-picker/series-preview"]'));
        self::assertStringNotContainsString('pick the specific edition', (string) $browser->getResponse()->getContent());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideVisibleCompetitionIds(): array
    {
        return [
            'live standalone competition' => [CompetitionFixture::COMPETITION_RECURRING_ONLINE, CompetitionFixture::COMPETITION_RECURRING_ONLINE],
            // An edition page's "Add my time" stays an explicit edition
            'edition of an approved series' => [EventsPageFixture::EDITION_SPRINT_SEASON, 'edition:' . EventsPageFixture::EDITION_SPRINT_SEASON],
        ];
    }

    #[DataProvider('provideVisibleCompetitionIds')]
    public function testCompetitionQueryParamPrefillsVisibleCompetition(string $competitionId, string $expectedValue): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add?competition=' . $competitionId);
        $this->assertResponseIsSuccessful();

        self::assertSame(
            $expectedValue,
            $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'),
        );
        // The picker offers what it pre-selects - an edition too, though no edition is in the default list
        self::assertStringContainsString('"' . $expectedValue . '"', $this->tomSelectOptions($crawler));
        self::assertFalse(
            $this->isCompetitionSectionHidden($crawler),
            'The competition section must be expanded when the deep link pre-selects an event',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonVisibleCompetitionQueryValues(): array
    {
        return [
            'unapproved standalone competition' => [CompetitionFixture::COMPETITION_UNAPPROVED],
            'edition of an unapproved series' => [CompetitionSeriesFixture::EDITION_UNAPPROVED_1],
            'not a uuid' => ['not-a-uuid'],
            'unknown uuid' => ['019999aa-0000-7000-8000-000000000000'],
        ];
    }

    #[DataProvider('provideNonVisibleCompetitionQueryValues')]
    public function testCompetitionQueryParamIgnoresNonVisible(string $queryValue): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add?competition=' . $queryValue);
        $this->assertResponseIsSuccessful();

        self::assertSame('', (string) $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
        self::assertTrue($this->isCompetitionSectionHidden($crawler));
    }

    public function testCompetitionQueryParamIgnoredInRelaxMode(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add?mode=relax&competition=' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $this->assertResponseIsSuccessful();

        self::assertSame('', (string) $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
        self::assertTrue($this->isCompetitionSectionHidden($crawler));
    }

    public function testSubmitWithNotSelectableCompetitionIsRejected(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->validSpeedPuzzlingSubmission($browser, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', "This competition or event can't be selected");
        self::assertSame($timesBefore, $this->countPlayerTimes($database));
    }

    /**
     * An empty co-puzzler row used to fall through to a 200 re-render of a valid form, which Turbo Drive
     * discards - production logged visitors clicking save six times in a row with nothing happening.
     */
    public function testEmptyCoPuzzlerIsRejectedWithVisibleError(): void
    {
        // The old co-puzzler rows - what PAIRS_TEAMS_PICKER_PUBLIC=0 falls back to. The picker cannot submit
        // an empty co-puzzler at all; the server refuses one either way.
        $this->overrideFeatureFlagEnv('PAIRS_TEAMS_PICKER_PUBLIC', false);

        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->validSpeedPuzzlingSubmission($browser, CompetitionSeriesFixture::EDITION_EJJ_68),
            'group_players' => [''],
        ], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', 'One of the puzzlers is empty');
        $this->assertSelectorExists('input[name="group_players[]"].is-invalid');
        self::assertSame($timesBefore, $this->countPlayerTimes($database));
    }

    public function testEmptyCoPuzzlerDoesNotBlockAddingToCollection(): void
    {
        // Collection mode hides the co-puzzler section, but its inputs are still posted
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $submission = $this->validSpeedPuzzlingSubmission($browser, CompetitionSeriesFixture::EDITION_EJJ_68);
        $submission['mode'] = 'collection';
        $submission['puzzle'] = PuzzleFixture::PUZZLE_1000_05;

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $submission,
            'group_players' => [''],
        ]);

        $this->assertResponseRedirects();
    }

    public function testSubmitLinksTheTimeToASeriesEdition(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->validSpeedPuzzlingSubmission($browser, CompetitionSeriesFixture::EDITION_EJJ_68),
        ]);

        $this->assertResponseRedirects();
        $location = $browser->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('/en/time-added/', $location);

        self::assertSame($timesBefore + 1, $this->countPlayerTimes($database));

        $timeId = substr($location, strlen('/en/time-added/'));
        self::assertSame(
            CompetitionSeriesFixture::EDITION_EJJ_68,
            $database->fetchOne('SELECT competition_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]),
        );
    }

    public function testResentFormLandsOnTheSavedResultInsteadOfSavingItAgain(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = $crawler->filter('input[name="time_id"]')->attr('value');
        self::assertNotNull($timeId);
        self::assertNotNull($crawler->filter('input[name="new_puzzle_id"]')->attr('value'));

        $submission = $this->submissionOf($crawler);

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        // The answer got lost, the player taps save again
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        $browser->followRedirect();
        $this->assertSelectorTextContains('body', 'This result was already saved');

        self::assertSame($timesBefore + 1, $this->countPlayerTimes($database));
        self::assertSame('resend_caught', $database->fetchOne(
            'SELECT kind FROM result_duplicate_prevention WHERE time_id = :timeId AND player_id = :playerId',
            ['timeId' => $timeId, 'playerId' => PlayerFixture::PLAYER_REGULAR],
        ));
        self::assertSame('form', $database->fetchOne('SELECT created_via FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testAResentFormWithAnotherTimeIsANewResult(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = $crawler->filter('input[name="time_id"]')->attr('value');
        self::assertNotNull($timeId);

        $submission = $this->submissionOf($crawler);

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        // Back to the form, the time corrected, saved again: not the same result, so not swallowed as a resend
        $submission['timeSeconds'] = '14';
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'time_id' => $timeId]);

        $this->assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/en/time-added/', $location);
        self::assertNotSame('/en/time-added/' . $timeId, $location);

        self::assertSame($timesBefore + 2, $this->countPlayerTimes($database));
        self::assertSame(2473, $database->fetchOne('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertSame(2474, $database->fetchOne('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', ['id' => substr($location, strlen('/en/time-added/'))]));
    }

    public function testANewPuzzleCanBeCorrectedAfterItsResultWasRefused(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = $crawler->filter('input[name="time_id"]')->attr('value');
        $newPuzzleId = $crawler->filter('input[name="new_puzzle_id"]')->attr('value');
        self::assertNotNull($timeId);
        self::assertNotNull($newPuzzleId);

        $submission = $this->submissionOf($crawler);
        $submission['puzzle'] = 'Mistyped Pieces Puzzle';
        // 500 meant: 41:13 for 5000 pieces is more than 100 pieces per minute
        $submission['puzzlePiecesCount'] = '5000';
        $ids = ['time_id' => $timeId, 'new_puzzle_id' => $newPuzzleId];

        $crawler = $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseStatusCodeSame(422);
        // The puzzle went in before the result was refused - the form still shows it as typed, its fields open
        self::assertSame(5000, $database->fetchOne('SELECT pieces_count FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]));
        self::assertSame($newPuzzleId, $crawler->filter('input[name="new_puzzle_id"]')->attr('value'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-time-form-autocomplete-target="newPuzzle"]')->attr('class'));

        $submission['puzzlePiecesCount'] = '500';
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(500, $database->fetchOne('SELECT pieces_count FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]));
        self::assertSame($newPuzzleId, $database->fetchOne('SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertSame(1, $database->fetchOne("SELECT COUNT(*) FROM puzzle WHERE name = 'Mistyped Pieces Puzzle'"));
    }

    public function testInvalidEanOfANewPuzzleIsRefusedKeepingThePhotoWithAClearButton(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $newPuzzleId = $crawler->filter('input[name="new_puzzle_id"]')->attr('value');
        $ids = ['time_id' => $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => $newPuzzleId];

        $submission = $this->submissionOf($crawler);
        $submission['puzzle'] = 'Pets of Palm Springs';
        $submission['puzzlePiecesCount'] = '500';
        $submission['puzzleEans'] = ['45555011897'];

        $crawler = $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertFalse($database->fetchOne('SELECT 1 FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]), 'nothing is saved');
        self::assertStringContainsString('looks like 4005555011897 with two zeros missing', $crawler->filter('[data-time-form-autocomplete-target="eanErrors"]')->text());
        self::assertCount(1, $crawler->filter('[data-action="click->time-form-autocomplete#clearEan"]'));
        // The box photo stays attached for the next submit
        self::assertSame('box.jpg', trim($crawler->filter('.file-drop-message')->first()->text()));

        $submission['puzzleEans'] = ['4005555011897'];
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseRedirects();
        self::assertSame('4005555011897', $database->fetchOne('SELECT ean FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]));
    }

    public function testANewPuzzleTakesOneBarcodeAndOneBrandCode(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        // A box carries one barcode and one brand code: one input each, the only "+" link is the other names'
        self::assertCount(1, $crawler->filter('input[name^="puzzle_add_form[puzzleEans]"]'));
        self::assertCount(1, $crawler->filter('input[name^="puzzle_add_form[puzzleBrandCodes]"]'));
        self::assertSame(['+ name in another language'], $crawler->filter('[data-action="optional-rows#add"]')->each(
            static fn (Crawler $link): string => trim($link->text()),
        ));
        // The scanner fills the EAN input, the phone shows its number pad
        $eanInput = $crawler->filter('[data-time-form-autocomplete-target="eanInput"]');
        self::assertSame('puzzle_add_form[puzzleEans][0]', $eanInput->attr('name'));
        self::assertSame('numeric', $eanInput->attr('inputmode'));

        $newPuzzleId = $crawler->filter('input[name="new_puzzle_id"]')->attr('value');
        $ids = ['time_id' => $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => $newPuzzleId];

        $submission = $this->submissionOf($crawler);
        $submission['puzzle'] = 'One Box Puzzle';
        $submission['puzzlePiecesCount'] = '500';
        $submission['puzzleEans'] = ['04005555011897'];
        $submission['puzzleBrandCodes'] = [' 17481 '];

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseRedirects();
        self::assertSame('4005555011897', $database->fetchOne('SELECT ean FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]));
        self::assertSame('17481', $database->fetchOne('SELECT identification_number FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]));
    }

    public function testAFormRenderedBeforeWithSeveralCodesKeepsThemAndMarksTheRefusedOne(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $newPuzzleId = $crawler->filter('input[name="new_puzzle_id"]')->attr('value');
        $ids = ['time_id' => $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => $newPuzzleId];

        // A page of the release before offered "+ another": it may post several codes
        $submission = $this->submissionOf($crawler);
        $submission['puzzle'] = 'Two Editions Puzzle';
        $submission['puzzlePiecesCount'] = '500';
        $submission['puzzleEans'] = [0 => '4005555011897', 2 => '', 3 => '4005555011898'];
        $submission['puzzleBrandCodes'] = [0 => '17481', 1 => ' 19748-2 '];

        $crawler = $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertFalse($database->fetchOne('SELECT 1 FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]), 'nothing is saved');
        // Every input comes back with what was typed, the refused code marked on its own input
        self::assertSame(['4005555011897', '', '4005555011898'], $crawler->filter('input[name^="puzzle_add_form[puzzleEans]"]')->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        ));
        self::assertSame(['17481', '19748-2'], $crawler->filter('input[name^="puzzle_add_form[puzzleBrandCodes]"]')->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        ));
        $refused = $crawler->filter('input[name="puzzle_add_form[puzzleEans][3]"]');
        self::assertStringContainsString('is-invalid', (string) $refused->attr('class'));
        self::assertStringNotContainsString('is-invalid', (string) $crawler->filter('input[name="puzzle_add_form[puzzleEans][0]"]')->attr('class'));
        self::assertSame('', trim($crawler->filter('[data-time-form-autocomplete-target="eanErrors"]')->text()), 'the first input is fine');
        self::assertStringContainsString('"4005555011898" is not a valid EAN', $refused->ancestors()->first()->text());

        $submission['puzzleEans'] = [0 => '4005555011897', 3 => '04005556197484'];
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseRedirects();
        self::assertSame('4005555011897, 4005556197484', $database->fetchOne('SELECT ean FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]));
        self::assertSame('17481, 19748-2', $database->fetchOne('SELECT identification_number FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]));
    }

    public function testANewPuzzleTakesTheNamesOfItsOtherBoxesKeptThroughARefusedSubmit(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        // Quiet: one link on the puzzle's label line, no row until it is tapped, all of it hidden until a new puzzle is typed
        self::assertSame('+ name in another language', trim($crawler->filter('.label-row [data-action="optional-rows#add"]')->text()));
        self::assertCount(0, $crawler->filter('[data-optional-rows-target="rows"] .extra-name-row'));
        self::assertStringContainsString('d-none', (string) $crawler->filter('.label-row [data-time-form-autocomplete-target="newPuzzleExtra"]')->attr('class'));

        $newPuzzleId = $crawler->filter('input[name="new_puzzle_id"]')->attr('value');
        $ids = ['time_id' => $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => $newPuzzleId];

        $submission = $this->submissionOf($crawler);
        $submission['puzzle'] = 'Circle of Colors: Seashells';
        $submission['puzzlePiecesCount'] = '500';
        // Refused first - the rows must come back
        $submission['puzzleEans'] = ['45555011897'];
        $names = ['alternativeNames' => [
            0 => ['name' => 'Kruh barev: Mušle', 'language' => 'cs'],
            // A row left empty is dropped
            3 => ['name' => '  ', 'language' => 'de'],
            5 => ['name' => 'Farbkreis: Muscheln', 'language' => 'de'],
        ]];

        $crawler = $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission + $names, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame(['Kruh barev: Mušle', 'Farbkreis: Muscheln'], $crawler->filter('[data-optional-rows-target="rows"] .extra-name-row__name')->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        ));
        self::assertSame('cs', $crawler->filter('select[name="puzzle_add_form[alternativeNames][0][language]"] option[selected]')->attr('value'));
        // A row added now does not take the index of one already there
        self::assertSame('6', $crawler->filter('[data-controller="optional-rows"]')->attr('data-optional-rows-index-value'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('.label-row [data-time-form-autocomplete-target="newPuzzleExtra"]')->attr('class'));

        $submission['puzzleEans'] = ['4005555011897'];
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission + $names, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        $this->assertResponseRedirects();
        $alternativeNames = $database->fetchOne('SELECT alternative_names FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]);
        self::assertIsString($alternativeNames);
        self::assertSame([
            ['name' => 'Kruh barev: Mušle', 'language' => 'cs'],
            ['name' => 'Farbkreis: Muscheln', 'language' => 'de'],
        ], json_decode($alternativeNames, true));
    }

    public function testANameAddedOnACzechPageStartsInCzech(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/pridat-puzzle');

        $this->assertResponseIsSuccessful();
        $prototype = (string) $crawler->filter('template[data-optional-rows-target="template"]')->html();
        self::assertMatchesRegularExpression('/<option value="cs"[^>]* selected/', $prototype);
    }

    public function testBothCodeLabelsExplainWhereTheCodesAreInThePagesLanguage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/pridat-puzzle');

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('.label-row button[data-bs-target="#puzzleCodesHelpModal"]');
        self::assertCount(2, $links);
        self::assertStringContainsString('Kód značky', $links->eq(1)->text());

        $modal = $crawler->filter('#puzzleCodesHelpModal');
        self::assertCount(1, $modal);
        $image = $modal->filter('img');
        self::assertStringContainsString('/img/puzzle-codes/box-side.cs.webp', (string) $image->attr('src'));
        self::assertSame('lazy', $image->attr('loading'));
    }

    public function testNamesLeftInTheHiddenRowsDoNotGoWithAResultOfAnExistingPuzzle(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $ids = ['time_id' => $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => $crawler->filter('input[name="new_puzzle_id"]')->attr('value')];
        $names = ['alternativeNames' => [['name' => 'Typed, then an existing puzzle picked', 'language' => 'cs']]];

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $this->submissionOf($crawler) + $names, ...$ids]);

        $this->assertResponseRedirects();
        self::assertSame('[]', $database->fetchOne('SELECT alternative_names FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]));
    }

    public function testInvalidEanLeftInTheHiddenFieldDoesNotBlockAResultOfAnExistingPuzzle(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $ids = ['time_id' => $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => $crawler->filter('input[name="new_puzzle_id"]')->attr('value')];

        $submission = $this->submissionOf($crawler);
        $submission['puzzleEans'] = ['45555011897'];

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids]);

        $this->assertResponseRedirects();
    }

    public function testInvalidTimeIdIsReplacedWithAFreshOne(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $this->submissionOf($crawler), 'time_id' => 'not-a-uuid']);

        $this->assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/en/time-added/', $location);
        self::assertTrue(Uuid::isValid(substr($location, strlen('/en/time-added/'))));
    }

    public function testRefusedFormKeepsItsIds(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = $crawler->filter('input[name="time_id"]')->attr('value');
        $newPuzzleId = $crawler->filter('input[name="new_puzzle_id"]')->attr('value');

        $submission = $this->submissionOf($crawler);
        unset($submission['puzzle']);

        $crawler = $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $submission,
            'time_id' => $timeId,
            'new_puzzle_id' => $newPuzzleId,
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame($timeId, $crawler->filter('input[name="time_id"]')->attr('value'));
        self::assertSame($newPuzzleId, $crawler->filter('input[name="new_puzzle_id"]')->attr('value'));
    }

    public function testStopwatchIsFinishedWithItsResultAndAResentSaveLandsOnTheResult(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $url = '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED;

        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $timeId = $crawler->filter('input[name="time_id"]')->attr('value');
        self::assertNotNull($timeId);

        $submission = $this->submissionOf($crawler);

        $browser->request('POST', $url, ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        self::assertSame('finished', $database->fetchOne('SELECT status FROM stopwatch WHERE id = :id', ['id' => StopwatchFixture::STOPWATCH_PAUSED]));
        self::assertSame('stopwatch', $database->fetchOne('SELECT created_via FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));

        // Sent again: the saved result, not "this stopwatch was already saved"
        $browser->request('POST', $url, ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        // Another form of the saved stopwatch (a second tab) is still turned away
        $browser->request('POST', $url, ['puzzle_add_form' => $submission]);
        $this->assertResponseRedirects('/en/my-profile');

        // So is its own form with another time - a stopwatch saves one result
        $submission['timeSeconds'] = (string) (((int) $submission['timeSeconds'] + 1) % 60);
        $browser->request('POST', $url, ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/my-profile');
    }

    /**
     * H12 scenario 1: a one-time event is linked explicitly, exactly as before
     */
    public function testScenario1AOneTimeEventIsLinkedAsBefore(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $timeId = $this->submitTime($browser, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, $this->formDate(-3));

        self::assertSame(
            ['competition_id' => EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, 'competition_series_id' => null, 'series_edition_match' => null],
            $this->linkWithoutRound($scenario, $timeId),
        );
    }

    /**
     * H12 scenario 2: a series pick is saved as one and MySpeedPuzzling finds the edition and its round by the puzzle
     */
    public function testScenario2ASeriesPickIsSavedAndMatchedByThePuzzle(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $roundId = $scenario->round($editionId, RoundCategory::Solo, $this->day(-9) . ' 19:00', puzzleIds: [PuzzleFixture::PUZZLE_500_01]);
        $scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));

        $timeId = $this->submitTime($browser, 'series:' . $seriesId, $this->formDate(-2));

        self::assertSame(
            ['competition_id' => $editionId, 'competition_series_id' => $seriesId, 'series_edition_match' => 'puzzle', 'competition_round_id' => $roundId],
            $scenario->link($timeId),
        );
    }

    /**
     * H12 scenario 6 / H13: a series without editions is a normal choice - the time is the series' result
     */
    public function testScenario6ASeriesWithoutEditionsSavesASeriesLevelTime(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $scenario->series('Copper Kettle Puzzle Cup');
        $crawler = $browser->request('GET', '/en/puzzle-add');
        self::assertStringContainsString('"series:' . $seriesId . '"', $this->tomSelectOptions($crawler));
        self::assertStringContainsString('No dates yet', $this->tomSelectOptions($crawler));

        $timeId = $this->submitTime($browser, 'series:' . $seriesId, $this->formDate(-1));

        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $seriesId, 'series_edition_match' => null, 'competition_round_id' => null],
            $scenario->link($timeId),
        );
    }

    /**
     * H12 scenario 5: an undated edition is picked explicitly - `edition:<uuid>`, and a bare uuid an older form posts (P2)
     */
    public function testScenario5AnEditionPickedExplicitlyIsLinkedExplicitly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $placeholder = $scenario->edition($seriesId, 'Summer Special', null);

        $timeId = $this->submitTime($browser, 'edition:' . $placeholder, $this->formDate(-1));
        self::assertSame(
            ['competition_id' => $placeholder, 'competition_series_id' => null, 'series_edition_match' => null],
            $this->linkWithoutRound($scenario, $timeId),
        );

        $timeId = $this->submitTime($browser, $placeholder, $this->formDate(-2), timeMinutes: '9');
        self::assertSame(
            ['competition_id' => $placeholder, 'competition_series_id' => null, 'series_edition_match' => null],
            $this->linkWithoutRound($scenario, $timeId),
        );
    }

    /**
     * H13: an edition without rounds keeps working as an explicit pick
     */
    public function testAnEditionWithoutRoundsCanBePickedExplicitly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));

        $timeId = $this->submitTime($browser, 'edition:' . $editionId, $this->formDate(-20));

        self::assertSame(
            ['competition_id' => $editionId, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $scenario->link($timeId),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMalformedCompetitionValues(): array
    {
        return [
            'series without a uuid' => ['series:not-a-uuid'],
            'edition without a uuid' => ['edition:'],
            'garbage' => ['<b>anything</b>'],
            'unknown edition' => ['edition:019999aa-0000-7000-8000-000000000000'],
            'unknown series' => ['series:019999aa-0000-7000-8000-000000000000'],
        ];
    }

    #[DataProvider('provideMalformedCompetitionValues')]
    public function testAMalformedOrUnknownValueIsRefusedWithTheGenericError(string $value): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->validSpeedPuzzlingSubmission($browser, $value),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', "This competition or event can't be selected");
        self::assertSame($timesBefore, $this->countPlayerTimes($database));
    }

    /**
     * H12 scenario 9: a draft edition or series is refused like any other value that is not offered - never by name
     */
    public function testScenario9ADraftEditionOrSeriesIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());
        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $draftEdition = $scenario->edition($seriesId, 'Jam No. 167 Draft', $this->day(-1), draft: true);
        $draftSeries = $scenario->series('Quiet Harbor Puzzle Series', draft: true);

        foreach (['edition:' . $draftEdition, $draftEdition, 'series:' . $draftSeries] as $value) {
            $browser->request('POST', '/en/puzzle-add', [
                'puzzle_add_form' => $this->validSpeedPuzzlingSubmission($browser, $value, $this->formDate(-1)),
            ]);

            $this->assertResponseStatusCodeSame(422);
            $this->assertSelectorTextContains('form[name="puzzle_add_form"]', "This competition or event can't be selected");
            $content = (string) $browser->getResponse()->getContent();
            self::assertStringNotContainsString('Jam No. 167 Draft', $content);
            self::assertStringNotContainsString('Quiet Harbor Puzzle Series', $content);
        }

        self::assertSame($timesBefore, $this->countPlayerTimes($database));
    }

    public function testARefusedSubmitKeepsTheEditionPickedByTyping(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));

        $submission = $this->validSpeedPuzzlingSubmission($browser, 'edition:' . $editionId, $this->formDate(-2));
        // No time - refused for another reason
        $submission['timeHours'] = '0';
        $submission['timeMinutes'] = '0';

        $crawler = $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame('edition:' . $editionId, $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
        self::assertStringContainsString('"edition:' . $editionId . '"', $this->tomSelectOptions($crawler));
        self::assertStringNotContainsString("This competition or event can't be selected", (string) $browser->getResponse()->getContent());
    }

    public function testSeriesQueryParamPreselectsAPublicSeries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $pendingSeries = $scenario->series('Willow Lane Puzzle Nights', public: false);

        $crawler = $browser->request('GET', '/en/puzzle-add?series=' . $seriesId);
        $this->assertResponseIsSuccessful();
        self::assertSame('series:' . $seriesId, $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
        self::assertFalse($this->isCompetitionSectionHidden($crawler));

        // Not public - nothing is pre-selected
        $crawler = $browser->request('GET', '/en/puzzle-add?series=' . $pendingSeries);
        self::assertSame('', (string) $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
        self::assertTrue($this->isCompetitionSectionHidden($crawler));

        // ?competition wins when both are sent
        $crawler = $browser->request('GET', '/en/puzzle-add?competition=' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND . '&series=' . $seriesId);
        self::assertSame(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
    }

    public function testTheStopwatchFinishSavesASeriesPick(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));

        $url = '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED;
        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('"series:' . $seriesId . '"', $this->tomSelectOptions($crawler));

        $timeId = $crawler->filter('input[name="time_id"]')->attr('value');
        self::assertNotNull($timeId);
        $submission = $this->submissionOf($crawler);
        $submission['finishedAt'] = $this->formDate(-2);
        $submission['competition'] = 'series:' . $seriesId;

        $browser->request('POST', $url, ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        self::assertSame(
            ['competition_id' => $editionId, 'competition_series_id' => $seriesId, 'series_edition_match' => 'date'],
            $this->linkWithoutRound($scenario, $timeId),
        );
    }

    private function submitTime(KernelBrowser $browser, string $competition, string $finishedAt, string $timeMinutes = '7'): string
    {
        $timeId = Uuid::uuid7()->toString();
        $submission = $this->validSpeedPuzzlingSubmission($browser, $competition, $finishedAt);
        $submission['timeMinutes'] = $timeMinutes;

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'time_id' => $timeId]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        return $timeId;
    }

    /**
     * @return array{competition_id: ?string, competition_series_id: ?string, series_edition_match: ?string}
     */
    private function linkWithoutRound(SeriesEditionScenario $scenario, string $timeId): array
    {
        $link = $scenario->link($timeId);
        unset($link['competition_round_id']);

        return $link;
    }

    private function day(int $offset): string
    {
        return self::getContainer()->get(ClockInterface::class)->now()->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function formDate(int $offset): string
    {
        return self::getContainer()->get(ClockInterface::class)->now()->modify(sprintf('%+d days', $offset))->format('d.m.Y');
    }

    private function tomSelectOptions(Crawler $crawler): string
    {
        $options = $crawler
            ->filter('input[name="puzzle_add_form[competition]"]')
            ->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($options);

        return $options;
    }

    private function boxPhoto(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'box_photo_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $path);

        return new UploadedFile($path, 'box.jpg', 'image/jpeg', null, true);
    }

    /**
     * @return array<string, string>
     */
    private function submissionOf(Crawler $form): array
    {
        $csrfToken = $form->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        return [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '0',
            'timeMinutes' => '41',
            'timeSeconds' => '13',
            'finishedAt' => '12.07.2026',
            'collection' => '__system_collection__',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validSpeedPuzzlingSubmission(KernelBrowser $browser, string $competitionId, string $finishedAt = '12.07.2026'): array
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        return [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => $finishedAt,
            'competition' => $competitionId,
            'collection' => '__system_collection__',
        ];
    }

    private function isCompetitionSectionHidden(Crawler $crawler): bool
    {
        $section = $crawler->filter('div[data-toggle-target="competition"]');
        self::assertCount(1, $section);

        $classes = explode(' ', (string) $section->attr('class'));

        return in_array('hidden', $classes, true);
    }

    private function countPlayerTimes(Connection $database): int
    {
        /** @var int|string $count */
        $count = $database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        return (int) $count;
    }
}
