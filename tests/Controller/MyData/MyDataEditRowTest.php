<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\MyData;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "My data", editing one row (docs/features/my-data.md "Editing one row"): the row sheet of a result and of a
 * collection puzzle, and one cell saved in place - who may, what each field stores, what a refusal answers, and that
 * the player's own values stay their own. What the sheet shares with the edit form is in MyDataEditParityTest.
 */
final class MyDataEditRowTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string ME = PlayerFixture::PLAYER_REGULAR;
    private const string ME_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    // #player3 - the partner of the pair results made here
    private const string PARTNER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string STRANGER = PlayerFixture::PLAYER_WITH_STRIPE;
    // A member with collections of their own
    private const string COLLECTOR = PlayerFixture::PLAYER_WITH_STRIPE;
    private const array SAME_ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const array JSON = ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'csrf-token', 'HTTP_ACCEPT' => 'application/json'];

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/my-data/result/' . PuzzleSolvingTimeFixture::TIME_06);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        $browser->request('POST', '/en/my-data/cell/result/' . PuzzleSolvingTimeFixture::TIME_06, ['column' => 'comment', 'from' => '', 'value' => 'x'], [], self::JSON);
        self::assertNotSame(200, $browser->getResponse()->getStatusCode());
        self::assertNull($this->stored(PuzzleSolvingTimeFixture::TIME_06)['comment']);
    }

    public function testSomebodyElsesRowIsNotThere(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        // TIME_12 is the pair of PLAYER_REGULAR and PLAYER_PRIVATE - PLAYER_WITH_STRIPE is on neither
        TestingLogin::asPlayer($browser, self::STRANGER);

        foreach ([PuzzleSolvingTimeFixture::TIME_06, PuzzleSolvingTimeFixture::TIME_12] as $timeId) {
            $browser->request('GET', '/en/my-data/result/' . $timeId);
            self::assertResponseStatusCodeSame(404);

            $browser->request('POST', '/en/my-data/result/' . $timeId, ['_token' => 'csrf-token', 'comment' => 'mine now', 'hours' => '0', 'minutes' => '1', 'seconds' => '0'], [], self::SAME_ORIGIN);
            self::assertResponseStatusCodeSame(404);

            $browser->request('POST', '/en/my-data/cell/result/' . $timeId, ['column' => 'comment', 'from' => '', 'value' => 'mine now'], [], self::JSON);
            self::assertResponseStatusCodeSame(404);
            self::assertNotSame('mine now', $this->stored($timeId)['comment']);
        }

        // A puzzle of somebody else's collection
        $item = $database->fetchOne('SELECT id FROM collection_item WHERE player_id = :playerId LIMIT 1', ['playerId' => self::COLLECTOR]);
        self::assertIsString($item);
        TestingLogin::asPlayer($browser, self::ME);

        $browser->request('GET', '/en/my-data/puzzle/' . $item);
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', '/en/my-data/puzzle/' . $item, ['_token' => 'csrf-token', 'comment' => 'mine now'], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', '/en/my-data/cell/puzzle/' . $item, ['column' => 'comment', 'from' => '', 'value' => 'mine now'], [], self::JSON);
        self::assertResponseStatusCodeSame(404);
        self::assertNotSame('mine now', $database->fetchOne('SELECT comment FROM collection_item WHERE id = :id', ['id' => $item]));

        // An id that is no row at all
        $browser->request('GET', '/en/my-data/result/' . Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTrackerAndARegisteredMemberMayEditAGroupResult(): void
    {
        $browser = self::createClient();
        // TIME_12: a pair tracked by PLAYER_REGULAR with PLAYER_PRIVATE
        $timeId = PuzzleSolvingTimeFixture::TIME_12;
        $before = $this->stored($timeId);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="my-data-row-save"]'));

        $browser->request('POST', '/en/my-data/result/' . $timeId, [...$this->sheetFields($crawler), 'comment' => 'The partner fixed it'], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(303);
        $after = $this->stored($timeId);
        self::assertSame('The partner fixed it', $after['comment']);
        // The result stays the tracker's, with the same people
        self::assertSame($before['player_id'], $after['player_id']);
        self::assertSame($before['puzzling_team_id'], $after['puzzling_team_id']);
        self::assertSame($before['seconds_to_solve'], $after['seconds_to_solve']);

        TestingLogin::asPlayer($browser, self::ME);
        $browser->request('POST', '/en/my-data/cell/result/' . $timeId, ['column' => 'comment', 'from' => 'The partner fixed it', 'value' => 'The tracker too'], [], self::JSON);
        self::assertResponseIsSuccessful();
        self::assertSame('The tracker too', $this->stored($timeId)['comment']);
    }

    public function testAnImportedEventResultIsReadOnly(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $timeId = $scenario->result(self::ME_USER, ['#player3']);
        // The event's result: nobody edits it, a claimer only has it on their profile (SolvedPuzzleDetail::isEditableBy())
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle_solving_time SET created_via = 'event_import' WHERE id = :id", ['id' => $timeId]);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);

        $table = $browser->request('GET', '/en/my-data');
        self::assertCount(0, $table->filter('tr[data-row-id="' . $timeId . '"] td[data-edit]:not([data-column^="f:"])'));

        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="my-data-row-read-only"]'));
        self::assertCount(0, $crawler->filter('input[name="hours"], input[name="day"], textarea[name="comment"]'));
        self::assertCount(0, $crawler->filter('[data-testid="my-data-row-full-form"]'));

        // Its fields can not be posted either - the player's own fields can
        $browser->request('POST', '/en/my-data/result/' . $timeId, ['_token' => 'csrf-token', 'hours' => '1', 'minutes' => '0', 'seconds' => '0', 'day' => '01.01.2020', 'comment' => 'changed', 'my_fields' => [$notes => 'my own note']], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(303);
        $stored = $this->stored($timeId);
        self::assertSame(5 * 3600, $stored['seconds_to_solve']);
        self::assertNull($stored['comment']);
        self::assertSame([$notes => 'my own note'], $scenario->storedValues($timeId, self::ME));

        foreach ([['time', '5:00:00', '1:00:00'], ['comment', '', 'changed'], ['first_try', '0', '1'], ['date', TestClock::now()->modify('-3 days')->format('Y-m-d'), '2020-01-01']] as [$column, $from, $value]) {
            $browser->request('POST', '/en/my-data/cell/result/' . $timeId, ['column' => $column, 'from' => $from, 'value' => $value], [], self::JSON);
            self::assertResponseStatusCodeSame(403, $column);
        }

        self::assertSame($stored, $this->stored($timeId));
    }

    public function testEveryFieldOfAResultIsSavedThroughTheSheetAndItGoesBackToTheTable(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $stars = $scenario->field(self::ME, 'Ocelot stars', CustomFieldType::Rating);
        $timeId = $scenario->result(self::ME_USER);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);

        $back = '/en/my-data?year=' . TestClock::now()->format('Y') . '&sort=time';
        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId . '?return=' . rawurlencode($back));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        $form = $crawler->filter('[data-testid="my-data-row-form"]');
        self::assertSame('/en/my-data/result/' . $timeId, $form->attr('action'));
        self::assertSame(['5', '0', '0'], [$form->filter('input[name="hours"]')->attr('value'), $form->filter('input[name="minutes"]')->attr('value'), $form->filter('input[name="seconds"]')->attr('value')]);
        self::assertSame(TestClock::now()->modify('-3 days')->format('d.m.Y'), $form->filter('input[name="day"]')->attr('value'));
        self::assertSame('/en/edit-time/' . $timeId, $form->filter('[data-testid="my-data-row-full-form"]')->attr('href'));
        self::assertSame($back, $form->filter('input[name="return"]')->attr('value'));
        // The edit form's own live check and notice
        self::assertSame('first-try-check', $form->attr('data-controller'));
        self::assertSame($timeId, $form->attr('data-first-try-check-time-value'));

        $day = TestClock::now()->modify('-40 days');
        $crawler = $this->saveSheet($browser, $timeId, [
            ...$this->sheetFields($crawler),
            'hours' => '4',
            'minutes' => '59',
            'seconds' => '58',
            'day' => $day->format('d.m.Y'),
            'first_try' => '1',
            'comment' => '  A rainy weekend  ',
            'my_fields' => [$notes => 'axolotl-whispers', $stars => '4'],
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame($back, $browser->getResponse()->headers->get('Location'));
        $stored = $this->stored($timeId);
        self::assertSame(4 * 3600 + 59 * 60 + 58, $stored['seconds_to_solve']);
        self::assertSame($day->format('Y-m-d'), substr((string) $stored['finished_at'], 0, 10));
        self::assertTrue($stored['first_attempt']);
        self::assertSame('A rainy weekend', $stored['comment']);
        self::assertSame([$notes => 'axolotl-whispers', $stars => 4], $scenario->storedValues($timeId, self::ME));

        $browser->followRedirect();
        self::assertSelectorTextContains('body', 'Edited information has been saved.');

        // An address that leaves the site is no way back
        $browser->request('POST', '/en/my-data/result/' . $timeId, [...$this->sheetFields($browser->request('GET', '/en/my-data/result/' . $timeId)), 'return' => '//evil.example/x', 'comment' => 'again'], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/en/my-data', $browser->getResponse()->headers->get('Location'));
    }

    public function testARefusedSaveAnswers422AtTheFieldAndSavesNothing(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $price = $scenario->field(self::ME, 'Price', CustomFieldType::Number);
        $timeId = $scenario->result(self::ME_USER);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);
        $before = $this->stored($timeId);
        $fields = [...$this->sheetFields($browser->request('GET', '/en/my-data/result/' . $timeId)), 'comment' => 'never stored'];

        $crawler = $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'minutes' => '75'], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the time as hours, minutes (0-59) and seconds (0-59).', trim($crawler->filter('[data-testid="my-data-row-time-error"]')->text()));
        // What was typed stays in the form
        self::assertSame('75', $crawler->filter('input[name="minutes"]')->attr('value'));
        self::assertSame('never stored', trim($crawler->filter('textarea[name="comment"]')->text()));

        // The edit form's own words for a result that needs a time and a day
        $crawler = $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'hours' => '0', 'minutes' => '0', 'seconds' => ''], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::trans('forms.time_required'), trim($crawler->filter('[data-testid="my-data-row-time-error"]')->text()));

        $crawler = $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'day' => ''], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Date completed is required for speed puzzling.', trim($crawler->filter('[data-testid="my-data-row-date-error"]')->text()));

        $crawler = $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'day' => '31.02.2024'], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Please enter a valid date.', trim($crawler->filter('[data-testid="my-data-row-date-error"]')->text()));

        // A value of an own field that can not be stored refuses the save as a whole - the result's fields too
        $crawler = $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'my_fields' => [$price => 'a lot']], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-custom-field="' . $price . '"] .invalid-feedback'));

        self::assertSame($before, $this->stored($timeId));
        self::assertNull($scenario->storedValues($timeId, self::ME));

        // A post from another site saves nothing (the form is stateless - config/packages/csrf.php)
        $browser->request('POST', '/en/my-data/result/' . $timeId, $fields, [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->stored($timeId));
    }

    public function testInTheModalTheSheetAnswersTheFrameAndASaveClosesItAndRefreshesTheTable(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $timeId = $scenario->result(self::ME_USER);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);
        $modal = ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml', ...self::SAME_ORIGIN];

        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId, [], [], $modal);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#modal-frame'));
        self::assertStringNotContainsString('<title>', (string) $browser->getResponse()->getContent());
        // A form in the modal frame names its own address
        self::assertSame('/en/my-data/result/' . $timeId, $crawler->filter('turbo-frame#modal-frame form')->attr('action'));
        $fields = $this->sheetFields($crawler);

        $refused = $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'seconds' => '99'], [], $modal);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $refused->filter('turbo-frame#modal-frame [data-testid="my-data-row-time-error"]'));

        $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'comment' => 'From the modal'], [], $modal);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));
        $stream = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="modal-frame">', $stream);
        self::assertStringContainsString('<turbo-stream action="refresh">', $stream);
        self::assertSame('From the modal', $this->stored($timeId)['comment']);
    }

    public function testAResultThatKnowsOnlyItsMonthKeepsItUntilADayIsPicked(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $timeId = $scenario->result(self::ME_USER);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle_solving_time SET finished_at = '2021-03-01 00:00:00', finished_at_precision = 'month' WHERE id = :id", ['id' => $timeId]);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertSame('', $crawler->filter('input[name="day"]')->attr('value'));
        self::assertStringContainsString('Mar 2021', $crawler->filter('[data-testid="my-data-row-period"]')->text());
        // The table's cell holds no exact day either
        $cell = $browser->request('GET', '/en/my-data?year=2021')->filter('tr[data-row-id="' . $timeId . '"] td[data-column="date"]');
        self::assertSame('date', $cell->attr('data-edit'));
        self::assertSame('', $cell->attr('data-value'));

        $this->saveSheet($browser, $timeId, [...$this->sheetFields($crawler), 'comment' => 'Still some day in March']);
        self::assertResponseStatusCodeSame(303);
        $stored = $this->stored($timeId);
        self::assertSame('Still some day in March', $stored['comment']);
        self::assertSame('month', $stored['finished_at_precision']);
        self::assertSame('2021-03-01', substr((string) $stored['finished_at'], 0, 10));

        $this->saveSheet($browser, $timeId, [...$this->sheetFields($browser->request('GET', '/en/my-data/result/' . $timeId)), 'day' => '14.03.2021']);
        self::assertResponseStatusCodeSame(303);
        $stored = $this->stored($timeId);
        self::assertNull($stored['finished_at_precision']);
        self::assertSame('2021-03-14', substr((string) $stored['finished_at'], 0, 10));
    }

    public function testAResultWithoutATimeKeepsBeingOne(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $timeId = $scenario->result(self::ME_USER);
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = NULL WHERE id = :id', ['id' => $timeId]);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertCount(0, $crawler->filter('input[name="hours"], input[name="first_try"]'));
        self::assertNull($crawler->filter('[data-testid="my-data-row-form"]')->attr('data-controller'));

        $browser->request('POST', '/en/my-data/result/' . $timeId, [...$this->sheetFields($crawler), 'hours' => '3', 'first_try' => '1', 'comment' => 'Just for fun'], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(303);
        $stored = $this->stored($timeId);
        self::assertNull($stored['seconds_to_solve']);
        self::assertFalse($stored['first_attempt']);
        self::assertSame('Just for fun', $stored['comment']);

        // No time and no first try to edit in place
        $row = $browser->request('GET', '/en/my-data')->filter('tr[data-row-id="' . $timeId . '"]');
        self::assertNull($row->filter('td[data-column="time"]')->attr('data-edit'));
        self::assertNull($row->filter('td[data-column="first_try"]')->attr('data-edit'));
        $browser->request('POST', '/en/my-data/cell/result/' . $timeId, ['column' => 'time', 'from' => '', 'value' => '1:00:00'], [], self::JSON);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNothingChangedSavesNothingAndTellsNobody(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PARTNER, 'Partner notes');
        $timeId = $scenario->result(self::ME_USER, ['#player3']);
        $scenario->forgetLoadedEntities();
        $database = self::getContainer()->get(Connection::class);
        $notifications = static fn (): int => (int) $database->fetchOne('SELECT COUNT(*) FROM notification'); // @phpstan-ignore cast.int
        $before = $notifications();
        // The partner fills their own field on the tracker's result: the result itself is not edited - nobody is told
        TestingLogin::asPlayer($browser, self::PARTNER);

        $browser->request('POST', '/en/my-data/result/' . $timeId, [...$this->sheetFields($browser->request('GET', '/en/my-data/result/' . $timeId)), 'my_fields' => [$notes => 'only mine']], [], self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(['only mine'], array_values((array) $scenario->storedValues($timeId, self::PARTNER)));
        self::assertNull($scenario->storedValues($timeId, self::ME));
        self::assertSame($before, $notifications());
        $browser->followRedirect();
        self::assertSelectorTextContains('body', 'Your fields were saved.');
    }

    public function testTheTableMarksWhatIsEditedInPlaceAndItsRowsOpenTheSheet(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $stars = $scenario->field(self::ME, 'Ocelot stars', CustomFieldType::Rating);
        $timeId = $scenario->result(self::ME_USER, time: '01:02:03');
        $scenario->setValues($timeId, self::ME, [$notes => 'axolotl', $stars => '4']);
        $lines = $scenario->result(self::ME_USER, time: '06:00:00');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle_solving_time SET comment = E'two\\nlines' WHERE id = :id", ['id' => $lines]);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data?sort=time&dir=desc');
        $table = $crawler->filter('[data-testid="my-data-table"]');
        self::assertSame('my-data-edit', $table->attr('data-controller'));
        $row = $table->filter('tr[data-row-id="' . $timeId . '"]');
        $here = '/en/my-data?sort=time&dir=desc';

        self::assertSame('/en/my-data/cell/result/' . $timeId, $row->attr('data-cell-url'));
        self::assertSame('/en/my-data/result/' . $timeId . '?return=' . $here, rawurldecode((string) $row->attr('data-row-url')));
        // From the table the sheet opens in the modal, from the phone's list as a page
        self::assertSame($row->attr('data-row-url'), $row->filter('[data-testid="my-data-open-row"]')->attr('href'));
        self::assertSame('modal-frame', $row->filter('[data-testid="my-data-open-row"]')->attr('data-turbo-frame'));
        $item = $crawler->filter('[data-testid="my-data-item"][data-row-id="' . $timeId . '"]');
        self::assertSame($row->attr('data-row-url'), $item->attr('href'));
        self::assertNull($item->attr('data-turbo-frame'));

        $cells = [];

        foreach (['time', 'date', 'first_try', 'comment', 'f:' . $notes, 'f:' . $stars] as $column) {
            $cell = $row->filter('td[data-column="' . $column . '"]');
            $cells[$column] = [$cell->attr('data-edit'), $cell->attr('data-value')];
            self::assertSame('0', $cell->attr('tabindex'), $column);
        }

        self::assertSame([
            'time' => ['time', '1:02:03'],
            'date' => ['date', TestClock::now()->modify('-3 days')->format('Y-m-d')],
            'first_try' => ['check', '0'],
            'comment' => ['text', ''],
            'f:' . $notes => ['text', 'axolotl'],
            'f:' . $stars => ['select', '4'],
        ], $cells);
        self::assertSame(['', '1', '2', '3', '4', '5'], array_column((array) json_decode((string) $table->filter('th[data-column="f:' . $stars . '"]')->attr('data-edit-options'), true), 0));

        // Nothing else is edited in place: the puzzle, the brand, the pieces, who solved it, how it was added
        foreach (['brand', 'pieces', 'group', 'source'] as $column) {
            self::assertNull($row->filter('td[data-column="' . $column . '"]')->attr('data-edit'), $column);
        }

        // A comment of several lines is edited in the row sheet only
        self::assertNull($table->filter('tr[data-row-id="' . $lines . '"] td[data-column="comment"]')->attr('data-edit'));

        // The wish list has nothing to edit
        $wishes = $browser->request('GET', '/en/my-data?tab=wishlist');
        self::assertNull($wishes->filter('[data-testid="my-data-table"]')->attr('data-controller'));
        self::assertCount(0, $wishes->filter('td[data-edit], [data-testid="my-data-open-row"]'));
    }

    public function testEachCellOfAResultIsSavedInPlace(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $stars = $scenario->field(self::ME, 'Ocelot stars', CustomFieldType::Rating);
        $timeId = $scenario->result(self::ME_USER, daysAgo: 0);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);
        $url = '/en/my-data/cell/result/' . $timeId;
        $today = TestClock::now()->format('Y-m-d');

        $answer = $this->cell($browser, $url, ['column' => 'comment', 'from' => '', 'value' => ' Rainy weekend ']);
        self::assertResponseIsSuccessful();
        self::assertSame('saved', $answer['status']);
        self::assertSame(['value' => 'Rainy weekend', 'text' => 'Rainy weekend'], $answer['cells']['comment']);
        self::assertSame('Rainy weekend', $this->stored($timeId)['comment']);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $day = TestClock::now()->modify('-40 days');
        $answer = $this->cell($browser, $url, ['column' => 'date', 'from' => $today, 'value' => $day->format('Y-m-d')]);
        self::assertResponseIsSuccessful();
        self::assertSame(['value' => $day->format('Y-m-d'), 'text' => $day->format('d.m.Y')], $answer['cells']['date']);
        self::assertSame($day->format('Y-m-d'), substr((string) $this->stored($timeId)['finished_at'], 0, 10));

        $answer = $this->cell($browser, $url, ['column' => 'first_try', 'from' => '0', 'value' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['value' => '1', 'text' => 'Yes'], $answer['cells']['first_try']);
        self::assertTrue($this->stored($timeId)['first_attempt']);

        // The same value again: nothing to save
        $answer = $this->cell($browser, $url, ['column' => 'first_try', 'from' => '1', 'value' => '1']);
        self::assertSame('unchanged', $answer['status']);

        // The time as it stands: the pieces per minute of the row come back with it
        $answer = $this->cell($browser, $url, ['column' => 'time', 'from' => '5:00:00', 'value' => '5:00:00']);
        self::assertSame('unchanged', $answer['status']);
        self::assertSame(['value' => '5:00:00', 'text' => '05:00:00', 'badge' => null], $answer['cells']['time']);
        self::assertSame('10.00', $answer['cells']['ppm']['text']);

        // The player's own fields: the value travels under the name nothing is logged with
        $answer = $this->cell($browser, $url, ['column' => 'f:' . $notes, 'my_fields' => ['from' => '', 'value' => 'axolotl-whispers']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['f:' . $notes => ['value' => 'axolotl-whispers', 'text' => 'axolotl-whispers']], $answer['cells']);
        $answer = $this->cell($browser, $url, ['column' => 'f:' . $stars, 'my_fields' => ['from' => '', 'value' => '4']]);
        self::assertSame('4', $answer['cells']['f:' . $stars]['value']);
        self::assertNotSame('', $answer['cells']['f:' . $stars]['text']);
        self::assertSame([$notes => 'axolotl-whispers', $stars => 4], $scenario->storedValues($timeId, self::ME));

        // Emptied: the value is taken away
        $this->cell($browser, $url, ['column' => 'f:' . $notes, 'my_fields' => ['from' => 'axolotl-whispers', 'value' => '']]);
        self::assertSame([$stars => 4], $scenario->storedValues($timeId, self::ME));
    }

    public function testACellChangedMeanwhileIsNotOverwritten(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $timeId = $scenario->result(self::ME_USER);
        $scenario->setValues($timeId, self::ME, [$notes => 'what another tab saved']);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle_solving_time SET comment = 'What another tab saved' WHERE id = :id", ['id' => $timeId]);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);
        $url = '/en/my-data/cell/result/' . $timeId;

        // The editor opened on an empty comment - it is no longer empty
        $answer = $this->cell($browser, $url, ['column' => 'comment', 'from' => '', 'value' => 'Mine']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('changed', $answer['status']);
        self::assertSame(['value' => 'What another tab saved', 'text' => 'What another tab saved'], $answer['cell']);
        self::assertSame('This was changed in the meantime - nothing was saved. This is what it holds now.', $answer['message']);
        self::assertSame('What another tab saved', $this->stored($timeId)['comment']);

        $answer = $this->cell($browser, $url, ['column' => 'time', 'from' => '4:00:00', 'value' => '4:30:00']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('5:00:00', $answer['cell']['value']);
        self::assertSame(5 * 3600, $this->stored($timeId)['seconds_to_solve']);

        $answer = $this->cell($browser, $url, ['column' => 'f:' . $notes, 'my_fields' => ['from' => '', 'value' => 'mine']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['value' => 'what another tab saved', 'text' => 'what another tab saved'], $answer['cell']);
        self::assertSame([$notes => 'what another tab saved'], $scenario->storedValues($timeId, self::ME));

        // With what the cell holds now it is saved
        $this->cell($browser, $url, ['column' => 'comment', 'from' => 'What another tab saved', 'value' => 'Mine']);
        self::assertResponseIsSuccessful();
        self::assertSame('Mine', $this->stored($timeId)['comment']);
    }

    public function testACellRefusesWhatTheSheetRefuses(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $price = $scenario->field(self::ME, 'Price', CustomFieldType::Number);
        $onItems = $scenario->field(self::ME, 'Tapir shelf', forResults: false, forCollectionItems: true);
        $timeId = $scenario->result(self::ME_USER);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);
        $url = '/en/my-data/cell/result/' . $timeId;
        $before = $this->stored($timeId);
        $day = TestClock::now()->modify('-3 days')->format('Y-m-d');

        foreach (
            [
                [['column' => 'time', 'from' => '5:00:00', 'value' => 'five hours'], 'Enter the time as hours, minutes (0-59) and seconds (0-59).'],
                [['column' => 'time', 'from' => '5:00:00', 'value' => '4:75:00'], 'Enter the time as hours, minutes (0-59) and seconds (0-59).'],
                [['column' => 'time', 'from' => '5:00:00', 'value' => '0:00:00'], self::trans('forms.time_required')],
                [['column' => 'date', 'from' => $day, 'value' => ''], 'Date completed is required for speed puzzling.'],
                [['column' => 'date', 'from' => $day, 'value' => '2024-02-31'], 'Please enter a valid date.'],
                [['column' => 'first_try', 'from' => '0', 'value' => 'yes'], 'This could not be saved - please try again.'],
                // Not a column that is edited
                [['column' => 'pieces', 'from' => '3000', 'value' => '500'], 'This could not be saved - please try again.'],
                [['column' => 'f:' . $price, 'my_fields' => ['from' => '', 'value' => 'a lot']], self::trans('my_fields.errors.not_a_number')],
                // A field of the player that is not filled on results, and a field that does not exist
                [['column' => 'f:' . $onItems, 'my_fields' => ['from' => '', 'value' => 'x']], self::trans('my_fields.errors.unknown_field')],
                [['column' => 'f:' . Uuid::uuid7()->toString(), 'my_fields' => ['from' => '', 'value' => 'x']], self::trans('my_fields.errors.unknown_field')],
            ] as [$parameters, $message]
        ) {
            $answer = $this->cell($browser, $url, $parameters);
            self::assertResponseStatusCodeSame(422, $parameters['column']);
            self::assertSame(['refused', $message, false], [$answer['status'], $answer['message'], $answer['row']], $parameters['column']);
        }

        // A post from another site
        $browser->request('POST', $url, ['column' => 'comment', 'from' => '', 'value' => 'x'], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/', 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame($before, $this->stored($timeId));
        self::assertNull($scenario->storedValues($timeId, self::ME));
    }

    public function testThePlayersOwnValuesStayTheirOwn(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $partnersField = $scenario->field(self::PARTNER, 'Partner notes');
        $timeId = $scenario->result(self::ME_USER, ['#player3']);
        $scenario->setValues($timeId, self::ME, [$notes => 'axolotl-whispers-at-dawn']);
        $scenario->forgetLoadedEntities();

        // The partner's sheet of the same result: their own field, nothing of mine
        TestingLogin::asPlayer($browser, self::PARTNER);
        $browser->request('GET', '/en/my-data/result/' . $timeId);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Partner notes', $content);

        foreach (['Quokka ledger', 'axolotl-whispers-at-dawn', $notes] as $notThere) {
            self::assertStringNotContainsString($notThere, $content);
        }

        // My field is no field of the partner - whatever they send, in the sheet or in a cell
        $browser->request('POST', '/en/my-data/result/' . $timeId, [...$this->sheetFields($browser->getCrawler()), 'my_fields' => [$notes => 'overwritten', $partnersField => 'the partner\'s own']], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(303);
        $answer = $this->cell($browser, '/en/my-data/cell/result/' . $timeId, ['column' => 'f:' . $notes, 'my_fields' => ['from' => 'axolotl-whispers-at-dawn', 'value' => 'overwritten']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('axolotl', (string) json_encode($answer));

        self::assertSame([$notes => 'axolotl-whispers-at-dawn'], $scenario->storedValues($timeId, self::ME));
        self::assertSame([$partnersField => 'the partner\'s own'], $scenario->storedValues($timeId, self::PARTNER));
    }

    public function testAPuzzleOfTheCollectionIsEditedAndMovedKeepingItsIdAndComment(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $database = self::getContainer()->get(Connection::class);
        $shelf = $scenario->field(self::COLLECTOR, 'Tapir shelf', forResults: false, forCollectionItems: true);
        // A puzzle the member has in one of their own collections and not in the main one
        $item = $database->fetchAssociative(
            'SELECT id, puzzle_id, added_at FROM collection_item item WHERE player_id = :playerId AND collection_id = :collectionId
                AND NOT EXISTS (SELECT 1 FROM collection_item main WHERE main.player_id = item.player_id AND main.puzzle_id = item.puzzle_id AND main.collection_id IS NULL) LIMIT 1',
            ['playerId' => self::COLLECTOR, 'collectionId' => CollectionFixture::COLLECTION_PUBLIC],
        );
        self::assertIsArray($item);
        $itemId = (string) $item['id']; // @phpstan-ignore cast.string
        TestingLogin::asPlayer($browser, self::COLLECTOR);

        $back = '/en/my-data?tab=puzzles&collection=main';
        $crawler = $browser->request('GET', '/en/my-data/puzzle/' . $itemId . '?return=' . rawurlencode($back));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $form = $crawler->filter('[data-testid="my-data-row-form"]');
        self::assertSame('/en/my-data/puzzle/' . $itemId, $form->attr('action'));
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $form->filter('select[name="collection"] option[selected]')->attr('value'));
        self::assertCount(1, $form->filter('select[name="collection"] option[value="main"]'));
        // Nobody else's collection is offered
        self::assertCount(0, $form->filter('select[name="collection"] option[value="' . CollectionFixture::COLLECTION_PRIVATE . '"]'));
        self::assertMatchesRegularExpression('/Solved \d+×|Not solved yet/', $form->filter('[data-testid="my-data-row-solved"]')->text());

        // A value that can not be stored, a collection that is not the player's: nothing is saved
        $before = $database->fetchAssociative('SELECT * FROM collection_item WHERE id = :id', ['id' => $itemId]);
        $refused = $browser->request('POST', '/en/my-data/puzzle/' . $itemId, ['_token' => 'csrf-token', 'comment' => 'never stored', 'collection' => CollectionFixture::COLLECTION_PRIVATE], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Choose one of your collections.', trim($refused->filter('[data-testid="my-data-row-collection-error"]')->text()));
        self::assertSame('never stored', trim($refused->filter('textarea[name="comment"]')->text()));
        self::assertSame($before, $database->fetchAssociative('SELECT * FROM collection_item WHERE id = :id', ['id' => $itemId]));

        $browser->request('POST', '/en/my-data/puzzle/' . $itemId, ['_token' => 'csrf-token', 'return' => $back, 'comment' => ' Missing one piece ', 'collection' => 'main', 'my_fields' => [$shelf => 'echidna-under-the-stairs']], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($back, $browser->getResponse()->headers->get('Location'));

        // The same item, in the main collection now: its id, its added date, its comment, its values
        $moved = $database->fetchAssociative('SELECT id, puzzle_id, added_at, collection_id, comment FROM collection_item WHERE id = :id', ['id' => $itemId]);
        self::assertSame(['id' => $itemId, 'puzzle_id' => $item['puzzle_id'], 'added_at' => $item['added_at'], 'collection_id' => null, 'comment' => 'Missing one piece'], $moved);
        self::assertSame([$shelf => 'echidna-under-the-stairs'], $scenario->storedItemValues($itemId));

        // Back into a collection that holds the puzzle already: two items would become one - refused before anything is saved
        $database->executeStatement(
            'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (:id, :collectionId, :playerId, :puzzleId, NULL, :addedAt)',
            ['id' => Uuid::uuid7()->toString(), 'collectionId' => CollectionFixture::COLLECTION_PUBLIC, 'playerId' => self::COLLECTOR, 'puzzleId' => $item['puzzle_id'], 'addedAt' => TestClock::now()->format('Y-m-d H:i:s')],
        );
        $scenario->forgetLoadedEntities();
        $refused = $browser->request('POST', '/en/my-data/puzzle/' . $itemId, ['_token' => 'csrf-token', 'comment' => 'changed', 'collection' => CollectionFixture::COLLECTION_PUBLIC], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('That collection already holds this puzzle.', trim($refused->filter('[data-testid="my-data-row-collection-error"]')->text()));
        self::assertSame('Missing one piece', $database->fetchOne('SELECT comment FROM collection_item WHERE id = :id', ['id' => $itemId]));

        // In place: the comment and the owner's own field
        $url = '/en/my-data/cell/puzzle/' . $itemId;
        $answer = $this->cell($browser, $url, ['column' => 'comment', 'from' => 'Missing one piece', 'value' => 'Complete after all']);
        self::assertResponseIsSuccessful();
        self::assertSame(['comment' => ['value' => 'Complete after all', 'text' => 'Complete after all']], $answer['cells']);
        $answer = $this->cell($browser, $url, ['column' => 'comment', 'from' => 'Missing one piece', 'value' => 'Stale']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Complete after all', $answer['cell']['value']);
        $answer = $this->cell($browser, $url, ['column' => 'f:' . $shelf, 'my_fields' => ['from' => 'echidna-under-the-stairs', 'value' => 'top shelf']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['f:' . $shelf => ['value' => 'top shelf', 'text' => 'top shelf']], $answer['cells']);
        $this->cell($browser, $url, ['column' => 'collection', 'from' => 'main', 'value' => CollectionFixture::COLLECTION_PUBLIC]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame('Complete after all', $database->fetchOne('SELECT comment FROM collection_item WHERE id = :id', ['id' => $itemId]));
        self::assertSame([$shelf => 'top shelf'], $scenario->storedItemValues($itemId));

        // The table: the row opens the sheet, the comment and the own field are edited in place
        $row = $browser->request('GET', '/en/my-data?tab=puzzles')->filter('tr[data-row-id="' . $itemId . '"]');
        self::assertSame('/en/my-data/cell/puzzle/' . $itemId, $row->attr('data-cell-url'));
        self::assertSame('/en/my-data/puzzle/' . $itemId . '?return=/en/my-data?tab=puzzles', rawurldecode((string) $row->filter('[data-testid="my-data-open-row"]')->attr('href')));
        self::assertSame(['text', 'Complete after all'], [$row->filter('td[data-column="comment"]')->attr('data-edit'), $row->filter('td[data-column="comment"]')->attr('data-value')]);
        self::assertSame('top shelf', $row->filter('td[data-column="f:' . $shelf . '"]')->attr('data-value'));
        self::assertNull($row->filter('td[data-column="collection"]')->attr('data-edit'));
    }

    public function testMovingBetweenCollectionsStaysTheMembersFeature(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        // PLAYER_REGULAR is no member
        $itemId = Uuid::uuid7()->toString();
        $puzzleId = $database->fetchOne('SELECT id FROM puzzle WHERE id NOT IN (SELECT puzzle_id FROM collection_item WHERE player_id = :playerId) LIMIT 1', ['playerId' => self::ME]);
        $database->executeStatement(
            'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (:id, NULL, :playerId, :puzzleId, NULL, :addedAt)',
            ['id' => $itemId, 'playerId' => self::ME, 'puzzleId' => $puzzleId, 'addedAt' => TestClock::now()->format('Y-m-d H:i:s')],
        );
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data/puzzle/' . $itemId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="collection"]'));

        // A posted collection is not read: the comment is saved, the puzzle stays where it is
        $browser->request('POST', '/en/my-data/puzzle/' . $itemId, ['_token' => 'csrf-token', 'comment' => 'Mine', 'collection' => CollectionFixture::COLLECTION_PUBLIC], [], self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/en/my-data?tab=puzzles', $browser->getResponse()->headers->get('Location'));
        self::assertSame(['comment' => 'Mine', 'collection_id' => null], $database->fetchAssociative('SELECT comment, collection_id FROM collection_item WHERE id = :id', ['id' => $itemId]));
    }

    public function testTheStatementsOfTheSheet(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $timeId = $scenario->result(self::ME_USER, ['#player3']);
        $scenario->setValues($timeId, self::ME, [$notes => 'axolotl']);
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);

        $browser->request('GET', '/en/my-data/result/' . $timeId);
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertResponseIsSuccessful();
        $withOneField = $this->queryCount($browser);

        // Beside what every signed-in page loads: the result, the player's fields, their values on it, how it was added
        $this->assertQueryCountAtMost($browser, 8, 'The row sheet of a result');

        // More fields and values cost no statement more
        foreach (['Dodo', 'Tapir', 'Ocelot', 'Numbat'] as $name) {
            $scenario->setValues($timeId, self::ME, [$scenario->field(self::ME, $name) => 'value of ' . $name]);
        }

        $scenario->forgetLoadedEntities();
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertSame($withOneField, $this->queryCount($browser));
    }

    /**
     * What the sheet's form holds - posted back as it is unless a test changes a field.
     *
     * @return array<string, string>
     */
    private function sheetFields(Crawler $crawler): array
    {
        $form = $crawler->filter('[data-testid="my-data-row-form"]');
        $fields = ['_token' => (string) $form->filter('input[name="_token"]')->attr('value')];

        foreach (['hours', 'minutes', 'seconds', 'day', 'return'] as $name) {
            if ($form->filter('input[name="' . $name . '"]')->count() > 0) {
                $fields[$name] = (string) $form->filter('input[name="' . $name . '"]')->attr('value');
            }
        }

        if ($form->filter('input[name="first_try"][checked]')->count() > 0) {
            $fields['first_try'] = '1';
        }

        if ($form->filter('textarea[name="comment"]')->count() > 0) {
            $fields['comment'] = $form->filter('textarea[name="comment"]')->text(normalizeWhitespace: false);
        }

        return $fields;
    }

    /**
     * Posts the sheet; a time far off the player's own is answered "Yes, it's right" (the question has its own
     * tests - MyDataEditParityTest).
     *
     * @param array<string, mixed> $fields
     */
    private function saveSheet(KernelBrowser $browser, string $timeId, array $fields): Crawler
    {
        $crawler = $browser->request('POST', '/en/my-data/result/' . $timeId, $fields, [], self::SAME_ORIGIN);
        $confirm = $crawler->filter('[data-action="first-try-check#confirmPace"]');

        if ($browser->getResponse()->getStatusCode() === 422 && $confirm->count() > 0) {
            $crawler = $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, 'pace_confirmed' => (string) $confirm->attr('data-first-try-check-key-param')], [], self::SAME_ORIGIN);
        }

        return $crawler;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{status: string, message: string, row: bool, cells: array<string, array{value: string, text: string, badge?: null|string}>, cell: array{value: string, text: string}}
     */
    private function cell(KernelBrowser $browser, string $url, array $parameters): array
    {
        $browser->request('POST', $url, $parameters, [], self::JSON);
        $answer = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($answer, (string) $browser->getResponse()->getContent());

        /** @var array{status: string, message: string, row: bool, cells: array<string, array{value: string, text: string, badge?: null|string}>, cell: array{value: string, text: string}} $answer what the test reads of it is asserted */
        return $answer;
    }

    /**
     * @return array{player_id: null|string, puzzle_id: string, puzzling_team_id: null|string, seconds_to_solve: null|int, finished_at: null|string, finished_at_precision: null|string, first_attempt: bool, comment: null|string, unboxed: bool, competition_id: null|string}
     */
    private function stored(string $timeId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT player_id, puzzle_id, puzzling_team_id, seconds_to_solve, finished_at, finished_at_precision, first_attempt, comment, unboxed, competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        /** @var array{player_id: null|string, puzzle_id: string, puzzling_team_id: null|string, seconds_to_solve: null|int, finished_at: null|string, finished_at_precision: null|string, first_attempt: bool, comment: null|string, unboxed: bool, competition_id: null|string} $row */
        return $row;
    }

    private static function trans(string $key): string
    {
        return self::getContainer()->get('translator')->trans($key, locale: 'en');
    }
}
