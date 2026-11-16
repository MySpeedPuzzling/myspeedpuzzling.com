<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "I didn't finish within the time limit" in the add form (docs/features/competitions-management/
 * unfinished-results-plan.md, step 4) through real requests: the question only where a round with a limit resolves,
 * the unfinished save beside the finished one, every refusal a 422 on its field, and nothing posted by a hidden switch
 * or another mode ever changing a finished, relax or collection save. WJPC 2024's Qualification Round (60 min, solo)
 * holds PUZZLE_500_02 (500 pieces).
 */
final class PuzzleAddUnfinishedResultTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string ADD_500_02 = '/en/puzzle-add/' . PuzzleFixture::PUZZLE_500_02;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testADeepLinkToACompetitionWithALimitedRoundAsksTheQuestion(): void
    {
        $browser = $this->signedIn();

        $crawler = $browser->request('GET', self::ADD_500_02 . '?competition=' . CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertResponseIsSuccessful();
        $question = $crawler->filter('[data-testid="unfinished-question"]');
        self::assertCount(1, $question);
        self::assertStringNotContainsString('d-none', (string) $question->attr('class'));
        self::assertStringContainsString('Qualification Round · 60 min limit', $question->text());
        self::assertNull($crawler->filter('input[name="puzzle_add_form[unfinished]"]')->attr('disabled'));
        // Off by default: no pieces yet, the time is the time
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-details"]')->attr('class'));
        self::assertSame('disabled', $crawler->filter('input[name="puzzle_add_form[piecesPlaced]"]')->attr('disabled'));
        self::assertSame('Time', trim($crawler->filter('[data-unfinished-result-target="timeLabel"]')->text()));
        self::assertStringContainsString('unfinished-result', (string) $crawler->filter('form[name="puzzle_add_form"]')->attr('data-controller'));
    }

    public function testWithoutACompetitionNothingIsAsked(): void
    {
        $browser = $this->signedIn();

        $crawler = $browser->request('GET', self::ADD_500_02);

        // Rendered for the browser to take over, hidden and not posted
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-question"]')->attr('class'));
        self::assertSame('disabled', $crawler->filter('input[name="puzzle_add_form[unfinished]"]')->attr('disabled'));
        self::assertSame('form-label required', $crawler->filter('[data-unfinished-result-target="timeLabel"]')->attr('class'));
        // An object even without a round - Stimulus refuses `null` for an Object value and the controller would not connect
        // (seen in the browser walk: the switch never appeared after picking a competition)
        self::assertSame('{}', $crawler->filter('form[name="puzzle_add_form"]')->attr('data-unfinished-result-round-value'));
    }

    public function testAnUnfinishedResultWithoutATotalIsSavedAndLandsOnTheRecap(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '479');

        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseRedirects('/en/time-added/' . $ids['time_id']);
        self::assertSame([
            'pieces_placed' => 479,
            'seconds_to_solve' => null,
            'finished_later_seconds' => null,
            'seconds_spent' => 3600,
            'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024,
            'competition_round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            'created_via' => 'form',
        ], $this->stored($ids['time_id']));

        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '479 / 500 pieces');
    }

    public function testATotalTimeOverTheLimitIsTheTimeTheyFinishedIn(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '479', ['1', '10', '5']);

        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseRedirects('/en/time-added/' . $ids['time_id']);
        $stored = $this->stored($ids['time_id']);
        self::assertSame(4205, $stored['finished_later_seconds']);
        self::assertNull($stored['seconds_to_solve']);
        self::assertSame(4205, $stored['seconds_spent']);
    }

    public function testAllPiecesPlacedIsRefusedOnThePiecesFieldAndTheFormKeepsItsState(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '500');

        $crawler = $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('All 500 pieces placed means you finished', $crawler->filter('[data-testid="unfinished-details"]')->text());
        self::assertSame('checked', $crawler->filter('input[name="puzzle_add_form[unfinished]"]')->attr('checked'));
        self::assertSame('500', $crawler->filter('input[name="puzzle_add_form[piecesPlaced]"]')->attr('value'));
        self::assertNull($crawler->filter('input[name="puzzle_add_form[piecesPlaced]"]')->attr('disabled'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-details"]')->attr('class'));
        // The time is the optional total while it is on
        self::assertSame('Total time, if you finished it afterwards (optional)', trim($crawler->filter('[data-unfinished-result-target="timeLabel"]')->text()));
        self::assertSame($ids['time_id'], $crawler->filter('input[name="time_id"]')->attr('value'));
        self::assertFalse($this->exists($ids['time_id']));
    }

    public function testATotalWithinTheLimitIsRefusedOnTheTime(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '479', ['0', '50', '0']);

        $crawler = $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('0:50:00 is within the 60 min limit', $crawler->filter('[data-puzzle-add-form-target="timeSection"]')->text());
        // Checked switch stays shown although the time is within the limit
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-question"]')->attr('class'));
        self::assertFalse($this->exists($ids['time_id']));
    }

    public function testNoPiecesIsRefused(): void
    {
        $browser = $this->signedIn();

        foreach (['', 'abc', '0'] as $pieces) {
            [$submission, $ids] = $this->unfinishedSubmission($browser, $pieces);

            $crawler = $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('How many pieces did you place', $crawler->filter('[data-testid="unfinished-details"]')->text(), $pieces);
            self::assertFalse($this->exists($ids['time_id']));
        }
    }

    public function testWithoutACompetitionAnUnfinishedSubmitIsRefused(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '479');
        unset($submission['competition']);

        $crawler = $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('needs a competition round with a time limit', $crawler->filter('[data-testid="unfinished-question"]')->text());
        self::assertFalse($this->exists($ids['time_id']));
    }

    public function testAPairOnASoloRoundsPuzzleHasNoRound(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '479');

        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, 'group_players' => ['#' . $this->codeOf(PlayerFixture::PLAYER_WITH_FAVORITES)], ...$ids]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->exists($ids['time_id']));
    }

    public function testANewPuzzleIsInNoRoundSoNothingIsCreated(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', '/en/puzzle-add?competition=' . CompetitionFixture::COMPETITION_WJPC_2024);
        $ids = $this->ids($crawler);
        $submission = [...$this->submissionOf($crawler), 'puzzle' => 'Unfinished New Puzzle', 'puzzlePiecesCount' => '500', 'unfinished' => '1', 'piecesPlaced' => '300'];

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$ids], ['puzzle_add_form' => ['puzzlePhoto' => $this->boxPhoto()]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM puzzle WHERE name = 'Unfinished New Puzzle'"));
    }

    public function testRelaxIgnoresAPostedSwitch(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, 'abc');
        $submission['mode'] = 'relax';

        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseRedirects('/en/tracking-added/' . $ids['time_id']);
        self::assertNull($this->stored($ids['time_id'])['pieces_placed']);
        self::assertNull($this->stored($ids['time_id'])['seconds_to_solve']);
    }

    public function testTheSwitchOffIgnoresPostedPieces(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '0', ['0', '41', '13']);
        unset($submission['unfinished']);

        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseRedirects('/en/time-added/' . $ids['time_id']);
        self::assertSame(2473, $this->stored($ids['time_id'])['seconds_to_solve']);
        self::assertNull($this->stored($ids['time_id'])['pieces_placed']);
    }

    public function testAResentUnfinishedFormLandsOnTheSavedResult(): void
    {
        $browser = $this->signedIn();
        [$submission, $ids] = $this->unfinishedSubmission($browser, '479');

        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);
        self::assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);
        self::assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :player AND puzzle_id = :puzzle AND pieces_placed IS NOT NULL',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'puzzle' => PuzzleFixture::PUZZLE_500_02],
        ));
    }

    public function testTheStopwatchTimePastTheLimitIsTheTotal(): void
    {
        $browser = $this->signedIn();
        $database = self::getContainer()->get(Connection::class);
        // The paused stopwatch measured 30 minutes of PUZZLE_500_01 - a 20 minute round
        $database->executeStatement('UPDATE competition_round SET minutes_limit = 20 WHERE id = :id', ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION]);
        $url = '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED;

        $crawler = $browser->request('GET', $url . '?competition=' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-question"]')->attr('class'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-nudge"]')->attr('class'));

        $ids = $this->ids($crawler);
        $submission = [
            ...$this->submissionOf($crawler),
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => (string) $crawler->filter('input[name="puzzle_add_form[timeHours]"]')->attr('value'),
            'timeMinutes' => (string) $crawler->filter('input[name="puzzle_add_form[timeMinutes]"]')->attr('value'),
            'timeSeconds' => (string) $crawler->filter('input[name="puzzle_add_form[timeSeconds]"]')->attr('value'),
            'competition' => CompetitionFixture::COMPETITION_WJPC_2024,
            'unfinished' => '1',
            'piecesPlaced' => '420',
        ];

        $browser->request('POST', $url . '?competition=' . CompetitionFixture::COMPETITION_WJPC_2024, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseRedirects('/en/time-added/' . $ids['time_id']);
        $stored = $this->stored($ids['time_id']);
        self::assertSame(420, $stored['pieces_placed']);
        self::assertSame(1800, $stored['finished_later_seconds']);
        self::assertSame('stopwatch', $stored['created_via']);
        self::assertSame('finished', $database->fetchOne('SELECT status FROM stopwatch WHERE id = :id', ['id' => StopwatchFixture::STOPWATCH_PAUSED]));
    }

    public function testTheKillSwitchAsksNothingAndIgnoresAPostedSwitch(): void
    {
        $this->overrideFeatureFlagEnv('UNFINISHED_RESULTS_FORM', false);
        $browser = $this->signedIn();

        $crawler = $browser->request('GET', self::ADD_500_02 . '?competition=' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertCount(0, $crawler->filter('[data-testid="unfinished-question"]'));
        self::assertCount(0, $crawler->filter('input[name="puzzle_add_form[unfinished]"]'));
        self::assertStringNotContainsString('unfinished-result', (string) $crawler->filter('form[name="puzzle_add_form"]')->attr('data-controller'));

        $ids = $this->ids($crawler);
        $submission = [...$this->submissionOf($crawler), 'puzzle' => PuzzleFixture::PUZZLE_500_02, 'competition' => CompetitionFixture::COMPETITION_WJPC_2024, 'unfinished' => '1', 'piecesPlaced' => '479'];
        $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseRedirects('/en/time-added/' . $ids['time_id']);
        self::assertNull($this->stored($ids['time_id'])['pieces_placed']);

        // The usual unfinished submit of a page rendered before the flip: no time - the time is required again (422)
        $crawler = $browser->request('GET', self::ADD_500_02 . '?competition=' . CompetitionFixture::COMPETITION_WJPC_2024);
        $ids = $this->ids($crawler);
        $submission = [...$this->submissionOf($crawler), 'puzzle' => PuzzleFixture::PUZZLE_500_02, 'timeHours' => '0', 'timeMinutes' => '0', 'timeSeconds' => '0', 'competition' => CompetitionFixture::COMPETITION_WJPC_2024, 'unfinished' => '1', 'piecesPlaced' => '479'];
        $crawler = $browser->request('POST', self::ADD_500_02, ['puzzle_add_form' => $submission, ...$ids]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please enter the time', $crawler->filter('[data-puzzle-add-form-target="timeSection"]')->text());
        self::assertFalse($this->exists($ids['time_id']));
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * @param array{string, string, string} $time
     * @return array{array<string, string>, array{time_id: string, new_puzzle_id: string}}
     */
    private function unfinishedSubmission(KernelBrowser $browser, string $pieces, array $time = ['0', '0', '0']): array
    {
        $crawler = $browser->request('GET', self::ADD_500_02 . '?competition=' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertResponseIsSuccessful();

        return [
            [
                ...$this->submissionOf($crawler),
                'puzzle' => PuzzleFixture::PUZZLE_500_02,
                'timeHours' => $time[0],
                'timeMinutes' => $time[1],
                'timeSeconds' => $time[2],
                'competition' => CompetitionFixture::COMPETITION_WJPC_2024,
                'unfinished' => '1',
                'piecesPlaced' => $pieces,
            ],
            $this->ids($crawler),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function submissionOf(Crawler $crawler): array
    {
        return [
            '_token' => (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
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
     * @return array{time_id: string, new_puzzle_id: string}
     */
    private function ids(Crawler $crawler): array
    {
        return [
            'time_id' => (string) $crawler->filter('input[name="time_id"]')->attr('value'),
            'new_puzzle_id' => (string) $crawler->filter('input[name="new_puzzle_id"]')->attr('value'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $timeId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT pieces_placed, seconds_to_solve, finished_later_seconds, seconds_spent, competition_id, competition_round_id, created_via FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row, 'The result was not saved.');

        return $row;
    }

    private function exists(string $timeId): bool
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]) !== false;
    }

    private function codeOf(string $playerId): string
    {
        $code = self::getContainer()->get(Connection::class)->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsString($code);

        return $code;
    }

    private function boxPhoto(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'box_photo_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $path);

        return new UploadedFile($path, 'box.jpg', 'image/jpeg', null, true);
    }
}
