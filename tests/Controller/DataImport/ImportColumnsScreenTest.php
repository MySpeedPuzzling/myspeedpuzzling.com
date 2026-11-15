<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ParseImportSheetHandler;
use SpeedPuzzling\Web\MessageHandler\ProposeImportMappingsHandler;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportPairingScenario;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/columns-screen.md - the "Sheets and columns" screen: every sheet in each of its states,
 * an answer and a changed column saved through the one message and read again, a refused change shown at its field
 * with a 422, another player's import a 404 on every route, and a bounded number of statements. Every file here is
 * made up; the import is built by the real handlers (the worker is played by work(), as in ImportParsingTest).
 */
final class ImportColumnsScreenTest extends WebTestCase
{
    use QueryCountAssertions;
    use ImportPairingScenario;

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testEverySheetIsACardInItsStateAndThoseThatWaitComeFirst(): void
    {
        $importId = $this->imported();
        $this->database->executeStatement("UPDATE data_import_sheet SET set_up_failure = 'parse_failed', parsed_version = NULL WHERE import_id = :id AND title = 'Competitions'", ['id' => $importId]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());

        self::assertSame(
            [
                // The sheet with a question first, then the file's order
                ['2023', 'waiting', 'Waiting for your answer', 'Results'],
                ['Puzzle List', 'parsed', 'Rows understood: 4 · Need a look: 0 · Left out: 1', 'Puzzle list the other sheets point to'],
                ['Results', 'parsed', 'Rows understood: 5 · Need a look: 1 · Left out: 2', 'Results'],
                ['Competitions', 'failed', 'We could not work this sheet out - a fault on our side', 'Results'],
                ['Stats', 'left_out', 'Left out', 'Not puzzles'],
            ],
            $crawler->filter('[data-testid="sheet-card"]')->each(static fn (Crawler $card): array => [
                trim($card->filter('[data-testid="sheet-card-title"]')->text()),
                $card->attr('data-sheet-state'),
                self::spaced($card->filter('[data-testid="sheet-state"]')->text()),
                trim($card->filter('[data-testid="sheet-card-kind-name"]')->text()),
            ]),
        );

        // The import in one line, and the honest line about what is not built yet
        self::assertSame('Rows ready: 9 · Need a look: 1 · Waiting for your answer: 2 · Sheets left out: 1', self::spaced($crawler->filter('[data-testid="import-summary"]')->text()));
        self::assertStringContainsString('Pairing your puzzles with the puzzle database and adding them to your profile arrive soon', $crawler->filter('[data-testid="import-soon"]')->text());

        // Nothing happens by itself any more: the live line is there for the script, hidden
        self::assertSame('false', $crawler->filter('[data-testid="import-setup-live"]')->attr('data-data-import-setup-working-value'));
        self::assertNotNull($crawler->filter('[data-testid="import-setup-live"]')->attr('hidden'));

        // The import's own page says the same and leads here
        $detail = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertSame('Rows ready: 9 · Need a look: 1 · Waiting for your answer: 2 · Sheets left out: 1', self::spaced($detail->filter('[data-testid="import-summary"]')->text()));
        self::assertSame('/en/import/' . $importId . '/columns', $detail->filter('[data-testid="import-open-columns"]')->attr('href'));
        self::assertStringContainsString('arrive soon', $detail->filter('[data-testid="import-soon"]')->text());
    }

    public function testTheImportsPageSaysWhereThePairingOfItsPuzzlesStands(): void
    {
        $importId = $this->imported();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // Nothing is said before the import's puzzles are built
        self::assertCount(0, $this->browser->request('GET', '/en/import/' . $importId)->filter('[data-testid="import-pairing-summary"]'));

        // The worker's next jobs: the puzzles of the parsed rows, paired with the catalogue (docs/features/data-import/pairing.md)
        $this->pair($importId);

        // Four puzzles on the list, named by five result rows and owned four times - none of them a catalogue puzzle
        self::assertSame(
            [['name' => 'Circle of Kites', 'brand_text' => 'Ravensburger', 'pieces_count' => 500, 'tier' => 'none', 'choice' => 'undecided']],
            $this->database->fetchAllAssociative("SELECT name, brand_text, pieces_count, tier, choice FROM data_import_puzzle WHERE import_id = :id AND name = 'Circle of Kites'", ['id' => $importId]),
        );
        $puzzles = $this->pairingCount('SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id', ['id' => $importId]);
        self::assertGreaterThanOrEqual(4, $puzzles);
        // Every row that is a puzzle names its puzzle, no other row does
        self::assertSame(0, $this->pairingCount(
            "SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND (import_puzzle_id IS NULL) = (puzzle_key IS NOT NULL AND kind <> 'not_a_puzzle' AND state <> 'left_out')",
            ['id' => $importId],
        ));
        self::assertNotNull($this->database->fetchOne('SELECT paired_at FROM data_import WHERE id = :id', ['id' => $importId]));
        self::assertSame('setting_up', $this->database->fetchOne('SELECT status FROM data_import WHERE id = :id', ['id' => $importId]));

        $detail = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertResponseIsSuccessful();
        self::assertSame('Different puzzles in your file: ' . $puzzles, self::spaced($detail->filter('[data-testid="import-pairing-puzzles"]')->text()));
        self::assertSame('Found in the database: 0 · Probably: 0 · To choose: 0 · Not found: ' . $puzzles, self::spaced($detail->filter('[data-testid="import-pairing-counts"]')->text()));
        self::assertCount(0, $detail->filter('[data-testid="import-pairing-searching"]'));

        // Somebody else's import says nothing to anybody else
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', '/en/import/' . $importId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testASheetShowsItsQuestionWithTheSheetsOwnExamplesReadEachWay(): void
    {
        $importId = $this->imported();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $this->sheetId($importId, '2023'));
        self::assertResponseIsSuccessful();

        self::assertSame('waiting', $crawler->filter('[data-testid="sheet-state"]')->attr('data-state'));
        $question = $crawler->filter('[data-testid="import-question"]');
        self::assertCount(1, $question);
        self::assertStringContainsString('How should we read the times in “Time”?', $question->text());
        self::assertSame(['45:10', '52:31'], $question->filter('[data-testid="import-question-example"]')->each(static fn (Crawler $example): string => $example->text()));
        self::assertSame(
            [
                ['minutes_seconds', 'Minutes and seconds 45:10 → 0:45:10 52:31 → 0:52:31'],
                ['hours_minutes', 'Hours and minutes 45:10 → 45:10:00 52:31 → 52:31:00'],
            ],
            $question->filter('[data-testid="import-question-choice"]')->each(static fn (Crawler $choice): array => [$choice->attr('data-value'), self::spaced($choice->text())]),
        );
        // Never chosen for the player
        self::assertCount(0, $question->filter('input[type="radio"][checked]'));

        // Its rows are read already: those the question decides say so - they are not rows that "need a look"
        $rows = $crawler->filter('[data-testid="import-sheet-preview"] [data-testid="parsed-row"]');
        self::assertCount(2, $rows);
        self::assertSame('true', $rows->eq(0)->filter('[data-testid="parsed-row-problem"]')->attr('data-waiting'));
        self::assertStringContainsString('This row waits for your answer', $rows->eq(0)->text());
        self::assertCount(0, $crawler->filter('[data-testid="import-sheet-need-look"]'));
        // The titles stand inside their cards - no legend that sits on a border
        self::assertCount(0, $crawler->filter('legend'));
        self::assertSame('How should we read the times in “Time”?', self::spaced($question->filter('h3')->text()));
    }

    public function testASheetShowsItsColumnsWithSamplesAndTheFirstRowsAsUnderstood(): void
    {
        $importId = $this->imported();
        $sheetId = $this->sheetId($importId, 'Results');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId);
        self::assertResponseIsSuccessful();
        self::assertSame('Rows understood: 5 · Need a look: 1 · Left out: 2', self::spaced($crawler->filter('[data-testid="sheet-state"]')->text()));

        // Every column of the table is there once, as a line (sure) or a card (to check), each with a picker
        $columns = $crawler->filter('[data-testid="import-column-line"], [data-testid="import-column-card"]');
        self::assertSame(['1', '2', '3', '4', '5'], $columns->each(static fn (Crawler $column): string => (string) $column->attr('data-column')));
        self::assertCount(5, $crawler->filter('[data-testid="import-column-picker"]'));
        self::assertSame('puzzle_name', $crawler->filter('select[name="columns[1]"] option[selected]')->attr('value'));
        self::assertSame('time', $crawler->filter('select[name="columns[3]"] option[selected]')->attr('value'));
        self::assertStringContainsString('Circle of Kites', $crawler->filter('[data-column="1"] [data-testid="import-column-samples"]')->text());
        self::assertStringContainsString('Puzzle → Puzzle name', self::spaced($crawler->filter('[data-column="1"]')->text()));
        // Where the rest comes from
        self::assertStringContainsString('“Puzzle List”', $crawler->filter('[data-testid="import-sheet-lookup"]')->text());

        // The first rows as understood: the puzzle, what was looked up, the time and the day
        $rows = $crawler->filter('[data-testid="import-sheet-preview"] [data-testid="parsed-row"]');
        self::assertCount(5, $rows);
        self::assertSame('Circle of Kites', trim($rows->eq(0)->filter('[data-testid="parsed-row-name"]')->text()));
        $first = self::spaced($rows->eq(0)->text());
        self::assertStringContainsString('Row 2', $first);
        self::assertStringContainsString('Result Ravensburger · Pieces: 500 · Time: 0:48:13 · Solved: Jan 10, 2026', $first);

        // And the way to the rows that need a look
        self::assertSame('/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look', $crawler->filter('[data-testid="import-sheet-need-look"]')->attr('href'));

        $look = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look');
        self::assertResponseIsSuccessful();
        $rows = $look->filter('[data-testid="parsed-row"]');
        self::assertCount(1, $rows);
        self::assertSame('7', $rows->attr('data-row'));
        self::assertSame('time_unreadable', $rows->filter('[data-testid="parsed-row-problem"]')->attr('data-code'));
        // The value as the sheet has it, and what is the matter in plain words
        self::assertSame('1:13:??', $rows->filter('[data-testid="parsed-row-problem-value"]')->text());
        self::assertStringContainsString('We could not read a time here.', $rows->text());
        self::assertCount(0, $look->filter('[data-testid="import-rows-pages"]'));
    }

    public function testASheetThatIsNotPuzzlesAndOneWhoseSetUpFailedSayWhatTheyAre(): void
    {
        $importId = $this->imported();
        $failed = $this->sheetId($importId, 'Competitions');
        $this->database->executeStatement("UPDATE data_import_sheet SET set_up_failure = 'parse_failed', parsed_version = NULL WHERE id = :id", ['id' => $failed]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $notPuzzles = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $this->sheetId($importId, 'Stats'));
        self::assertResponseIsSuccessful();
        self::assertSame('left_out', $notPuzzles->filter('[data-testid="sheet-state"]')->attr('data-state'));
        self::assertSame('not_puzzles', $notPuzzles->filter('[data-testid="import-sheet-kind"] input[checked]')->attr('value'));
        // Nothing of it is imported, so no columns are asked about - only what it is can be changed
        self::assertCount(0, $notPuzzles->filter('[data-testid="import-column-picker"]'));
        self::assertCount(1, $notPuzzles->filter('[data-testid="import-sheet-save"]'));

        $page = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $failed);
        self::assertResponseIsSuccessful();
        self::assertSame('failed', $page->filter('[data-testid="sheet-state"]')->attr('data-state'));
        self::assertStringContainsString('Save the sheet once more below', $page->filter('[data-testid="import-sheet-failed-hint"]')->text());
        self::assertCount(0, $page->filter('[data-testid="import-sheet-preview"]'));

        // Saving it starts its set-up over
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $failed, ['_token' => $this->token($page), 'version' => '1']);
        self::assertResponseRedirects('/en/import/' . $importId . '/sheets/' . $failed);
        $this->work();
        self::assertSame('parsed', $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $failed)->filter('[data-testid="sheet-state"]')->attr('data-state'));

        // A sheet the import has not recognised yet has nothing to set up: the list shows it, and gets its card by itself
        $this->database->executeStatement('UPDATE data_import_sheet SET mapping_version = 0, mapping = NULL, parsed_version = NULL WHERE id = :id', ['id' => $failed]);
        $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $failed);
        self::assertResponseRedirects('/en/import/' . $importId . '/columns');
        $list = $this->browser->followRedirect();
        self::assertSame('true', $list->filter('[data-testid="import-setup-live"]')->attr('data-data-import-setup-working-value'));
        self::assertCount(1, $list->filter('[data-sheet-state="recognising"]'));
    }

    public function testAnAnswerIsSavedTheSheetIsReadAgainAndTheCountsChange(): void
    {
        $importId = $this->imported();
        $sheetId = $this->sheetId($importId, '2023');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId);

        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, [
            '_token' => $this->token($page),
            'version' => '1',
            'answers' => ['duration_shape.two_part_text' => 'minutes_seconds'],
        ]);
        self::assertResponseRedirects('/en/import/' . $importId . '/sheets/' . $sheetId);
        // The worker's turn comes later (the queue of a test is emptied with every request)
        $queued = $this->queued();
        self::assertCount(1, $queued);

        // Saved, not read yet: the page says so, and the progress the live line asks for counts the rows
        $crawler = $this->browser->followRedirect();
        self::assertSame('parsing', $crawler->filter('[data-testid="sheet-state"]')->attr('data-state'));
        self::assertCount(0, $crawler->filter('[data-testid="import-question"]'));
        $live = $crawler->filter('[data-testid="import-setup-live"]');
        self::assertSame('true', $live->attr('data-data-import-setup-working-value'));
        self::assertNull($live->attr('hidden'));
        self::assertSame('Reading your rows… %done% of %total%', $live->attr('data-data-import-setup-rows-text-value'));

        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        $before = json_decode((string) $this->browser->getResponse()->getContent(), true);
        self::assertIsArray($before);
        // The page carries the revision it shows: a sheet that finishes before the script's first question is seen
        self::assertSame($before['revision'], $live->attr('data-data-import-setup-revision-value'));
        self::assertSame([true, 3, 0], [$before['working'], $before['rowsToParse'], $before['rowsParsed']]);

        $this->handle($queued);

        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        $after = json_decode((string) $this->browser->getResponse()->getContent(), true);
        self::assertIsArray($after);
        self::assertSame([false, 0, 0], [$after['working'], $after['rowsToParse'], $after['rowsParsed']]);
        // What makes the page load its live parts again
        self::assertNotSame($before['revision'], $after['revision']);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId);
        self::assertSame('Rows understood: 2 · Need a look: 0 · Left out: 1', self::spaced($crawler->filter('[data-testid="sheet-state"]')->text()));
        self::assertStringContainsString('Time: 0:45:10', $crawler->filter('[data-testid="import-sheet-preview"] [data-testid="parsed-row"]')->eq(0)->text());

        $list = $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        self::assertSame('Rows ready: 13 · Need a look: 1 · Waiting for your answer: 0 · Sheets left out: 1', self::spaced($list->filter('[data-testid="import-summary"]')->text()));
    }

    public function testAQuestionThatDecidesAFewRowsDoesNotHoldBackItsSheet(): void
    {
        // Twenty times nobody can read two ways, and two that can be minutes:seconds or hours:minutes
        $text = RawXlsx::inline(...);
        $rows = [1 => [$text('Puzzle'), $text('Pieces'), $text('Time')], 2 => [$text('Plum Orchard'), RawXlsx::n('500'), $text('45:10')], 3 => [$text('Chalk Cliffs'), RawXlsx::n('500'), $text('52:31')]];

        for ($number = 4; $number <= 23; $number++) {
            $rows[$number] = [$text('Invented puzzle ' . $number), RawXlsx::n('500'), $text(sprintf('1:%02d:%02d', $number, $number + 7))];
        }

        $importId = $this->imported(sheets: ['Log' => $rows]);
        $sheetId = $this->sheetId($importId, 'Log');
        self::assertSame(
            ['rows_understood' => 20, 'rows_need_look' => 0, 'rows_waiting' => 2, 'parsed_version' => 1, 'questions' => 1],
            $this->database->fetchAssociative("SELECT rows_understood, rows_need_look, rows_waiting, parsed_version, jsonb_array_length(mapping -> 'questions') AS questions FROM data_import_sheet WHERE id = :id", ['id' => $sheetId]),
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // The card shows the sheet's counts - and the question as a button, with the rows it decides
        $list = $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        $card = $list->filter('[data-testid="sheet-card"]');
        self::assertSame('parsed', $card->attr('data-sheet-state'));
        self::assertSame('Rows understood: 20 · Need a look: 0 · Waiting for your answer: 2 · Left out: 1', self::spaced($card->filter('[data-testid="sheet-state"]')->text()));
        self::assertSame('Questions to answer: 1 (rows: 2)', $card->filter('[data-testid="sheet-card-answer-some"]')->text());
        self::assertSame('Rows ready: 20 · Need a look: 0 · Waiting for your answer: 2 · Sheets left out: 0', self::spaced($list->filter('[data-testid="import-summary"]')->text()));

        // The rows that wait are not rows that "need a look"
        $look = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look');
        self::assertCount(0, $look->filter('[data-testid="parsed-row"]'));

        // Answered: read again, nothing waits any more
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId);
        self::assertCount(1, $page->filter('[data-testid="import-question"]'));
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => $this->token($page), 'version' => '1', 'answers' => ['duration_shape.two_part_text' => 'minutes_seconds']]);
        self::assertCount(1, $this->work());
        $list = $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        self::assertSame('Rows understood: 22 · Need a look: 0 · Left out: 1', self::spaced($list->filter('[data-testid="sheet-state"]')->text()));
        self::assertCount(0, $list->filter('[data-testid="sheet-card-answer-some"]'));
    }

    public function testAChangedColumnAndAOneTapKindAreSavedAsThePlayersWord(): void
    {
        $importId = $this->imported();
        $sheetId = $this->sheetId($importId, 'Results');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId);

        // "Notes" becomes the public comment; everything else is sent as the page had it
        $form = ['_token' => $this->token($page), 'version' => '1', 'columns' => []];

        foreach ($page->filter('[data-testid="import-column-picker"]') as $select) {
            $column = (new Crawler($select))->closest('[data-column]')?->attr('data-column');
            $form['columns'][(string) $column] = (string) (new Crawler($select))->filter('option[selected]')->attr('value');
        }

        $form['columns']['5'] = 'public_comment';
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, $form);
        self::assertResponseRedirects('/en/import/' . $importId . '/sheets/' . $sheetId);
        $this->work();

        $mapping = $this->mapping($sheetId);
        self::assertSame(['public_comment', 'certain', 'player'], [$mapping['columns'][4]['field'], $mapping['columns'][4]['certainty'], $mapping['columns'][4]['reason']]);
        // A column the player left alone keeps what we knew of it
        self::assertSame(['time', 'header_and_values'], [$mapping['columns'][2]['field'], $mapping['columns'][2]['reason']]);
        self::assertSame(2, $this->database->fetchOne('SELECT parsed_version FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]));
        self::assertSame('typed twice', $this->database->fetchOne("SELECT parsed ->> 'comment' FROM data_import_row WHERE sheet_id = :id AND row_number = 5", ['id' => $sheetId]));

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId);
        self::assertStringContainsString('Notes → Public comment', self::spaced($crawler->filter('[data-testid="import-column-line"][data-column="5"]')->text()));

        // One tap on the list: "Competitions" is not puzzles after all
        $competitions = $this->sheetId($importId, 'Competitions');
        $list = $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $competitions, ['_token' => $this->token($list), 'version' => '1', 'back' => 'list', 'kind' => 'not_puzzles']);
        self::assertResponseRedirects('/en/import/' . $importId . '/columns');
        $this->work();

        $list = $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        $card = $list->filter('[data-testid="sheet-card"]')->reduce(static fn (Crawler $card): bool => trim($card->filter('[data-testid="sheet-card-title"]')->text()) === 'Competitions');
        self::assertSame(['left_out', 'not_puzzles'], [$card->attr('data-sheet-state'), $card->filter('[data-testid="sheet-card-kind-name"]')->attr('data-kind')]);
        self::assertStringContainsString('Sheets left out: 2', $list->filter('[data-testid="import-summary"]')->text());
    }

    public function testARefusedChangeIsShownAtItsFieldWithA422AndChangesNothing(): void
    {
        $importId = $this->imported();
        $sheetId = $this->sheetId($importId, 'Results');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId));
        $version = fn (): mixed => $this->database->fetchOne('SELECT mapping_version FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]);

        // Two columns can not both be the time: said at the column the player changed, their choice kept
        $crawler = $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => $token, 'version' => '1', 'columns' => ['5' => 'time']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('nothing was saved yet', $crawler->filter('[data-testid="import-sheet-error"]')->text());
        $column = $crawler->filter('[data-column="5"]');
        self::assertStringContainsString('Another column is already this', $column->filter('[data-testid="import-column-error"]')->text());
        self::assertSame('time', $column->filter('select option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-testid="import-column-error"]'));
        self::assertSame(1, $version());

        // Something the page never offers: refused by the handler's own check, in plain words
        $crawler = $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => $token, 'version' => '1', 'answers' => ['year' => '1999']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('We could not save this, so nothing was changed', $crawler->filter('[data-testid="import-sheet-error"]')->text());
        $crawler = $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => $token, 'version' => '1', 'columns' => ['5' => 'no_such_field']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please choose one of the listed fields', $crawler->filter('[data-testid="import-column-error"]')->text());
        self::assertSame(1, $version());
        self::assertSame([], $this->queued());

        // "A column says it for each row" on a sheet without such a column - the page never offers it
        $crawler = $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => $token, 'version' => '1', 'row_meaning' => 'by_column']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('We could not save this, so nothing was changed', $crawler->filter('[data-testid="import-sheet-error"]')->text());
        self::assertSame(1, $version());

        // A save that changes nothing reads nothing again
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => $token, 'version' => '1', 'kind' => 'results', 'row_meaning' => 'result', 'solving_type' => '', 'columns' => ['3' => 'time']]);
        self::assertResponseRedirects('/en/import/' . $importId . '/sheets/' . $sheetId);
        self::assertSame([], $this->queued());
        self::assertStringContainsString('Nothing was changed', (string) $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(1, $version());

        // A page that is out of date (the sheet was changed in another tab): the sheet as it stands, with a notice
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => $token, 'version' => '7', 'columns' => ['5' => 'public_comment']]);
        self::assertResponseRedirects('/en/import/' . $importId . '/sheets/' . $sheetId);
        self::assertStringContainsString('This sheet was changed in the meantime', (string) $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(1, $version());

        // Without the form's token nothing is taken
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['version' => '1', 'kind' => 'not_puzzles']);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, ['_token' => 'made-up', 'version' => '1', 'kind' => 'not_puzzles']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $version());
    }

    public function testAnotherPlayersImportIsNotFoundOnEveryRoute(): void
    {
        $importId = $this->imported();
        $sheetId = $this->sheetId($importId, 'Results');
        $othersImport = $this->imported(PlayerFixture::PLAYER_WITH_STRIPE);
        $othersSheet = $this->sheetId($othersImport, 'Results');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->token($this->browser->request('GET', '/en/import/' . $othersImport . '/columns'));

        foreach (['/columns', '/sheets/' . $sheetId, '/sheets/' . $sheetId . '/need-a-look', '/sheets/' . $othersSheet, '/sheets/' . $othersSheet . '/need-a-look'] as $path) {
            // Somebody else's import - and somebody else's import with a sheet of one's own
            $this->browser->request('GET', '/en/import/' . $importId . $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        // One's own import with a sheet of somebody else's
        $this->browser->request('GET', '/en/import/' . $othersImport . '/sheets/' . $sheetId);
        self::assertResponseStatusCodeSame(404);

        foreach ([[$importId, $sheetId], [$othersImport, $sheetId], [$importId, $othersSheet]] as [$import, $sheet]) {
            $this->browser->request('POST', '/en/import/' . $import . '/sheets/' . $sheet, ['_token' => $token, 'version' => '1', 'kind' => 'not_puzzles']);
            self::assertResponseStatusCodeSame(404);
        }

        self::assertSame(1, $this->database->fetchOne('SELECT mapping_version FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]));
        self::assertSame([], $this->queued());

        // Signed out: the sign-in page, never the import
        $this->browser->restart();
        $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        self::assertResponseRedirects();
    }

    public function testRowsThatNeedALookComeFiftyAPageAndThePagesCostTheSameWhateverTheSheetHolds(): void
    {
        // A results sheet of 330 rows; every third one has a time nobody can read - 110 rows that need a look
        $rows = [1 => [RawXlsx::inline('Puzzle'), RawXlsx::inline('Pieces'), RawXlsx::inline('Date'), RawXlsx::inline('Time')]];

        for ($number = 2; $number <= 331; $number++) {
            $rows[$number] = [
                RawXlsx::inline('Invented puzzle ' . $number),
                RawXlsx::n('500'),
                RawXlsx::inline('2026-01-' . str_pad((string) ($number % 28 + 1), 2, '0', STR_PAD_LEFT)),
                RawXlsx::inline($number % 3 === 0 ? 'about an hour <b>' . $number . '</b>' : sprintf('1:%02d:%02d', $number % 60, 1 + $number % 59)),
            ];
        }

        $importId = $this->imported(sheets: ['Log' => $rows]);
        $sheetId = $this->sheetId($importId, 'Log');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $first = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look');
        self::assertResponseIsSuccessful();
        self::assertCount(50, $first->filter('[data-testid="parsed-row"]'));
        self::assertSame('Page 1 of 3', trim($first->filter('[data-testid="import-rows-pages"] span.fs-sm')->text()));
        self::assertCount(0, $first->filter('[data-testid="import-rows-previous"]'));
        // A cell's value is text on the page - never markup, never part of an address
        self::assertSame('about an hour <b>3</b>', $first->filter('[data-testid="parsed-row-problem-value"]')->eq(0)->text());
        self::assertStringNotContainsString('<b>3</b>', (string) $this->browser->getResponse()->getContent());
        self::assertSame('/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look?page=2', $first->filter('[data-testid="import-rows-next"]')->attr('href'));

        $last = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look?page=3');
        self::assertCount(10, $last->filter('[data-testid="parsed-row"]'));
        self::assertSame('330', $last->filter('[data-testid="parsed-row"]')->last()->attr('data-row'));
        // A page past the end is the last one
        self::assertCount(10, $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look?page=99')->filter('[data-testid="parsed-row"]'));

        // The budgets: the same for 330 rows on one sheet as for a handful on five
        $small = $this->imported(PlayerFixture::PLAYER_WITH_STRIPE);
        $budgets = [];

        foreach ([[PlayerFixture::PLAYER_REGULAR, $importId, $sheetId], [PlayerFixture::PLAYER_WITH_STRIPE, $small, $this->sheetId($small, 'Results')]] as [$player, $import, $sheet]) {
            TestingLogin::asPlayer($this->browser, $player);

            foreach (['columns' => '/columns', 'sheet' => '/sheets/' . $sheet, 'rows' => '/sheets/' . $sheet . '/need-a-look'] as $name => $path) {
                $this->browser->request('GET', '/en/import/' . $import . $path);
                $this->startCountingQueries($this->browser);
                $this->browser->request('GET', '/en/import/' . $import . $path);
                self::assertResponseIsSuccessful();
                $budgets[$name][] = $this->queryCount($this->browser);
            }
        }

        foreach ($budgets as $name => [$large, $few]) {
            self::assertSame($few, $large, $name . ': the number of statements must not grow with rows or sheets');
        }

        // What the screen itself reads, on top of what every signed-in page loads: the import, its sheets, the
        // revision the live line starts from and how many layouts the player saved (4); the sheet, a dozen first
        // rows, the first rows as understood, the import and the revision (5); the sheet, a page of rows and the
        // import (3)
        $signedIn = $budgets['columns'][0] - 4;
        self::assertLessThanOrEqual(8, $signedIn, 'what every signed-in page loads');
        self::assertSame([$signedIn + 5, $signedIn + 3], [$budgets['sheet'][0], $budgets['rows'][0]]);
    }

    /**
     * An import read, recognised and parsed by the real handlers: the training workbook of ImportParsingTest (a
     * puzzle list, results that name the puzzle only, the same result on a second sheet, statistics, and a sheet
     * whose times nothing settles) - or the sheets given. Every puzzle is invented.
     *
     * @param null|array<string, array<int, list<null|string>>> $sheets title => row number => cells from column A
     */
    private function imported(string $playerId = PlayerFixture::PLAYER_REGULAR, null|array $sheets = null): string
    {
        $text = RawXlsx::inline(...);
        $number = static fn (int $value): string => RawXlsx::n((string) $value);
        $time = static fn (int $seconds): string => RawXlsx::n((string) ($seconds / 86400), 1);
        $date = static fn (string $day): string => RawXlsx::n((string) (intdiv((int) (new \DateTimeImmutable($day . ' UTC'))->format('U'), 86400) + 25569), 2);

        $sheets ??= [
            'Puzzle List' => [
                1 => [$text('Puzzle'), $text('Brand'), $text('Pieces')],
                2 => [$text('Circle of Kites'), $text('Ravensburger'), $number(500)],
                3 => [$text('Glass Marbles'), $text('Jumbo'), $number(500)],
                4 => [$text('Tea House'), $text('Trefl'), $number(500)],
                5 => [$text('Marble Run'), $text('Ravensburger'), $number(500)],
            ],
            'Results' => [
                1 => [$text('Puzzle'), $text('Date'), $text('Time'), $text('Attempt'), $text('Notes')],
                2 => [$text('Circle of Kites'), $date('2026-01-10'), $time(2893), $number(1)],
                3 => [$text('Glass Marbles'), $date('2026-02-08'), $time(2750), $number(3)],
                4 => [$text('Glass Marbles'), $date('2026-02-08'), $time(2750), $number(4)],
                5 => [$text('Tea House'), $date('2026-02-01'), $time(2411), null, $text('typed twice')],
                6 => [$text('Tea House'), $date('2026-02-01'), $time(2411), null, $text('typed twice')],
                7 => [$text('Marble Run'), $date('2026-02-15'), $text('1:13:??'), $number(1)],
                8 => [$text('Marble Run')],
            ],
            'Competitions' => [
                1 => [$text('Puzzle'), $text('Date'), $text('Time')],
                2 => [$text('Circle of Kites'), $date('2026-01-10'), $time(2893)],
                3 => [$text('Tea House'), $date('2026-03-07'), $time(2380)],
            ],
            'Stats' => [
                1 => [$text('Month'), $text('Hours')],
                2 => [$text('January'), $number(4)],
            ],
            '2023' => [
                1 => [$text('Puzzle'), $text('Pieces'), $text('Time')],
                2 => [$text('Plum Orchard'), $number(500), $text('45:10')],
                3 => [$text('Chalk Cliffs'), $number(500), $text('52:31')],
            ],
        ];

        $workbook = (new RawXlsx())->styles(['[h]:mm:ss', 'yyyy-mm-dd']);

        foreach ($sheets as $title => $rows) {
            $cells = [];

            foreach ($rows as $rowNumber => $row) {
                foreach ($row as $index => $cell) {
                    if ($cell !== null) {
                        $cells[$rowNumber][['A', 'B', 'C', 'D', 'E'][$index]] = $cell;
                    }
                }
            }

            $workbook->sheet((string) $title, RawXlsx::worksheet($cells));
        }

        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);
        $workbook->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        $importId = Uuid::uuid7()->toString();
        $this->bus->dispatch(new StartImport($importId, $playerId, 'Training.xlsx', strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey($playerId, $importId, DataImportFileKind::Xlsx), $content);
        $this->bus->dispatch(new ConfirmImportUploaded($importId, $playerId));
        $this->work();

        return $importId;
    }

    /**
     * The worker: every queued message goes to its handler, and what those queue too - until nothing is left.
     *
     * @return list<object> the messages handled, in order
     */
    private function work(): array
    {
        $handled = [];

        while (($messages = $this->queued()) !== []) {
            $this->queue()->reset();

            $this->handle($messages);
            $handled = [...$handled, ...$messages];
        }

        return $handled;
    }

    /**
     * @param list<object> $messages
     */
    private function handle(array $messages): void
    {
        foreach ($messages as $message) {
            $handler = match (true) {
                $message instanceof ReadImportFile => ReadImportFileHandler::class,
                $message instanceof ProposeImportMappings => ProposeImportMappingsHandler::class,
                $message instanceof ParseImportSheet => ParseImportSheetHandler::class,
                default => self::fail('Nothing handles ' . $message::class),
            };

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $entityManager->clear();
            $service = self::getContainer()->get($handler);
            self::assertIsCallable($service);
            $service($message);
            $entityManager->clear();
        }
    }

    /**
     * What waits on the queue - without the pairing's own messages (BuildImportPuzzles after every parse, and the
     * search it asks for): those are ImportPuzzleMatcherTest's, and the worker of this test lets them go.
     *
     * @return list<object>
     */
    private function queued(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->queue()->getSent()),
            static fn (object $message): bool => $message instanceof BuildImportPuzzles === false && $message instanceof MatchImportPuzzlesFuzzy === false,
        ));
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function sheetId(string $importId, string $title): string
    {
        $id = $this->database->fetchOne('SELECT id FROM data_import_sheet WHERE import_id = :id AND title = :title', ['id' => $importId, 'title' => $title]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array{columns: list<array<string, mixed>>}
     */
    private function mapping(string $sheetId): array
    {
        $json = $this->database->fetchOne('SELECT mapping FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]);
        self::assertIsString($json);
        /** @var array{columns: list<array<string, mixed>>} $mapping */
        $mapping = json_decode($json, true);

        return $mapping;
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('form[action*="/sheets/"] input[name="_token"]')->first()->attr('value');
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
