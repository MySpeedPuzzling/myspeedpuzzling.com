<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\ConfirmImportMapping;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\RequestImportSetUp;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\Message\StartImportSheetOver;
use SpeedPuzzling\Web\MessageHandler\BuildImportPuzzlesHandler;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportBrandResolver;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/columns.md "Check first", guided-flow.md §5.3: a sheet whose reading is a guess - here a
 * sheet without a header row - is read by the real handlers, but its rows are held: no puzzle is built from them and
 * none can be added, the flow's one button is "Check how we read your file", and the sheet's page says why. The
 * player's "Yes, this is right" (or a change of the columns) releases it; a sheet we are sure about, in the same
 * file, goes on as ever. Every puzzle here is made up (the two brands are the test catalogue's).
 */
final class ImportCheckFirstScreensTest extends WebTestCase
{
    use ImportedWorkbookScenario;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

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

    public function testASheetWithoutAHeaderRowIsHeldUntilThePlayerSaysItIsRight(): void
    {
        $importId = $this->uploaded();
        $log = $this->sheet($importId, 'Log');
        $results = $this->sheet($importId, 'Results');

        // ---- Recognised from its values - the brand with the catalogue's help - and asked to be checked ----
        $mapping = $this->mapping($log);
        self::assertSame('no_header', $mapping['regions'][0]['source']);
        self::assertSame(['check_columns'], array_column($mapping['questions'], 'type'));
        $fields = [];

        foreach ($mapping['columns'] as $column) {
            $fields[$column['column']] = [$column['field'], $column['certainty'], $column['reason']];
        }

        self::assertSame(['solved_on', 'probable', 'values'], $fields[1]);
        self::assertSame(['brand', 'probable', 'brand_values'], $fields[2]);
        self::assertSame(['pieces', 'probable', 'values'], $fields[3]);
        self::assertSame(['puzzle_name', 'probable', 'values'], $fields[4]);
        self::assertSame(['time', 'probable', 'values'], $fields[5]);
        // The sheet with a header row we know asks nothing
        self::assertSame([], $this->mapping($results)['questions']);

        // ---- Its rows are read, and held: no puzzle, no key - nothing is built on them ----
        self::assertSame([1, 1, 0, 0, 6], array_values($this->standing($log)));
        self::assertSame(6, $this->rowsWhere("sheet_id = :sheet AND kind = 'result' AND state = 'waiting' AND puzzle_key IS NULL AND source_key IS NULL AND problems @> '[{\"code\": \"columns_open\", \"waiting\": true}]'", $log));
        self::assertSame(3, $this->rowsWhere('sheet_id = :sheet AND puzzle_key IS NOT NULL AND source_key IS NOT NULL AND problems IS NULL', $results));

        $this->buildPuzzles($importId);
        // Only the puzzles of the sheet we are sure about are matched; nobody of the held sheet is asked about
        self::assertSame(['Zq Check Canal', 'Zq Check Meadow', 'Zq Check Orchard'], $this->puzzles($importId));
        self::assertSame(0, $this->rowsWhere('sheet_id = :sheet AND import_puzzle_id IS NOT NULL', $log));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_person WHERE import_id = :id', ['id' => $importId]));

        // "Ready to add" never holds a row of the held sheet
        $this->database->executeStatement("UPDATE data_import_puzzle SET choice = 'unlisted' WHERE import_id = :id", ['id' => $importId]);
        self::assertSame(3, self::getContainer()->get(GetImportAddScreen::class)->summary($importId, self::PLAYER)->toAdd());

        // ---- Nothing treats the held sheet as stuck: no step that runs by itself is queued for it ----
        $this->database->executeStatement("UPDATE data_import SET last_activity_at = last_activity_at - INTERVAL '2 hours' WHERE id = :id", ['id' => $importId]);
        $now = TestClock::now();
        $standing = fn (): array => self::getContainer()->get(DataImportRepository::class)->idsSetUpStandingSince($now->modify('-15 minutes'), $now->modify('-7 days'), 100);
        self::assertNotContains($importId, $standing());
        // (The guard does see this import when a sheet of it really is not read)
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = NULL WHERE id = :id', ['id' => $results]);
        self::assertContains($importId, $standing());
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1 WHERE id = :id', ['id' => $results]);
        $this->bus->dispatch(new RequestImportSetUp($importId));
        self::assertSame([], $this->queuedMessages());

        // ---- "Where you are": the one button leads to that sheet's columns, and says why ----
        TestingLogin::asPlayer($this->browser, self::PLAYER);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        // The header (the dots, "Step 2 of 5") is a live part like the list of steps: both follow the re-read
        self::assertSame('2', $crawler->filter('turbo-frame#import-flow-header-live[data-import-live] [data-testid="import-flow-header"]')->attr('data-step'));
        self::assertCount(1, $crawler->filter('turbo-frame#import-detail-live[data-import-live]'));
        $primary = $crawler->filter('[data-testid="import-flow-primary"]');
        self::assertSame('data_import_sheet', $primary->attr('data-route'));
        self::assertSame('/en/import/' . $importId . '/sheets/' . $log, $primary->attr('href'));
        self::assertStringContainsString('Check how we read your file', $primary->text());
        $lines = $crawler->filter('[data-testid="import-flow-lines"]')->text();
        self::assertStringContainsString('One sheet of your file has no header row, so we guessed what each column is - please check.', $lines);
        // "9 rows": the header row of the other sheet is not one of the rows we say we read
        self::assertStringContainsString('2 sheets, 9 rows', $lines);
        self::assertStringContainsString('1 sheet waits for you to check its columns', $lines);
        // What is ready - the other sheet - can be added now: 3, never the held rows
        self::assertSame('3', $crawler->filter('[data-testid="import-flow-add-ready"]')->attr('data-ready'));

        // ---- Step 2's summary: the sheet first, with the same button ----
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/columns');
        $this->assertResponseIsSuccessful();
        $first = $crawler->filter('[data-testid="check-sheet"]')->first();
        self::assertSame(['waiting', 'check'], [$first->attr('data-sheet-state'), $first->attr('data-mark')]);
        self::assertSame('no_header', $first->filter('[data-testid="check-sheet-check-first"]')->attr('data-reason'));
        self::assertStringContainsString('Waits for you to check its columns', $first->text());
        self::assertSame('/en/import/' . $importId . '/sheets/' . $log, $crawler->filter('[data-testid="import-flow-check-first"]')->attr('href'));
        self::assertStringContainsString('Waits for you to check its columns', $crawler->filter('[data-testid="import-sheet-check-first"]')->text());

        // ---- The sheet's page: the notice, every guess marked, examples of each column, one tap to confirm ----
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $log);
        $this->assertResponseIsSuccessful();
        $notice = $crawler->filter('[data-testid="import-sheet-check-first"]');
        self::assertSame('no_header', $notice->attr('data-reason'));
        self::assertStringContainsString('This sheet has no header row.', $notice->text());
        // The only sheet that asks, and its time column was found: neither "Sheet 1 of 2" nor the missing-times line
        self::assertCount(0, $crawler->filter('[data-testid="import-sheet-check-position"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-sheet-no-time"]'));
        self::assertStringContainsString('We guessed the columns from what is in them - tell us what each column is.', $notice->text());
        self::assertStringContainsString('Yes, this is right', $notice->filter('[data-testid="import-sheet-check-yes"]')->text());
        self::assertSame('yes', $crawler->filter('input[name="answers[check_columns]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-testid="sheet-state-check-first"]'));
        // It is not one of the questions with choices
        self::assertCount(0, $crawler->filter('[data-testid="import-question"]'));
        // Five columns guessed, each marked as a guess and shown with values of its own
        self::assertCount(5, $crawler->filter('[data-testid="import-column-card"] [data-testid="import-column-guess"]'));
        self::assertCount(1, $crawler->filter('[data-testid="import-column-brand-guess"]'));
        $brand = $crawler->filter('[data-testid="import-column-card"][data-column="2"]');
        self::assertStringContainsString('Ravensburger', $brand->filter('[data-testid="import-column-samples"]')->text());
        self::assertSame('brand', $brand->filter('select option[selected]')->attr('value'));
        // A brand column was found: no hint about a missing one here - but on the sheet that has none
        self::assertCount(0, $crawler->filter('[data-testid="import-sheet-brand-hint"]'));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $results);
        self::assertStringContainsString('choose “Brand” for it below', $crawler->filter('[data-testid="import-sheet-brand-hint"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="import-sheet-check-first"]'));

        // ---- A row of the held sheet, opened on its own page: it waits for the check - nothing to fix there ----
        $rowId = $this->database->fetchOne('SELECT id FROM data_import_row WHERE sheet_id = :sheet ORDER BY row_number LIMIT 1', ['sheet' => $log]);
        self::assertIsString($rowId);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/rows/' . $rowId);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('it waits until you have checked how we read the sheet', $crawler->filter('[data-testid="import-row-columns-open"]')->text());
        self::assertSame('/en/import/' . $importId . '/sheets/' . $log, $crawler->filter('[data-testid="import-row-check-columns"]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="import-row-form"]'));
        // The way back is its sheet - not "Rows we could not read": the row reads fine
        $back = $crawler->filter('[data-testid="import-flow-back-primary"]');
        self::assertSame('/en/import/' . $importId . '/sheets/' . $log, $back->attr('href'));
        self::assertStringContainsString('Back to the sheet', $back->text());
        self::assertStringNotContainsString('We could not read this for sure', $crawler->filter('[data-testid="import-row-page"]')->text());

        // ---- "Yes, this is right": nothing changed, and that is the player's word - what was held back starts ----
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $log);
        $this->browser->submit($crawler->filter('[data-testid="import-sheet-check-yes"]')->form());
        // No other sheet asks: on to "Where you are"
        $this->assertResponseRedirects('/en/import/' . $importId);
        $this->workImportsQueue();

        // The sheet reads as sure from now on: every guess is the player's word
        $confirmed = $this->mapping($log);
        self::assertSame('certain', $confirmed['kindCertainty']);
        self::assertSame([['certain', 'player']], array_values(array_unique(array_map(static fn (array $column): array => [$column['certainty'], $column['reason']], $confirmed['columns']), SORT_REGULAR)));
        self::assertSame(['solved_on', 'brand', 'pieces', 'puzzle_name', 'time'], array_column($confirmed['columns'], 'field'));
        self::assertSame([], $this->mapping($log)['questions']);
        self::assertTrue((bool) $this->database->fetchOne('SELECT confirmed_by_player FROM data_import_sheet WHERE id = :id', ['id' => $log]));
        self::assertSame([2, 2, 6, 0, 0], array_values($this->standing($log)));
        self::assertSame(6, $this->rowsWhere('sheet_id = :sheet AND puzzle_key IS NOT NULL AND source_key IS NOT NULL AND problems IS NULL', $log));

        $this->buildPuzzles($importId);
        self::assertCount(9, $this->puzzles($importId));
        self::assertSame(6, $this->rowsWhere('sheet_id = :sheet AND import_puzzle_id IS NOT NULL', $log));
        $this->database->executeStatement("UPDATE data_import_puzzle SET choice = 'unlisted' WHERE import_id = :id", ['id' => $importId]);
        self::assertSame(9, self::getContainer()->get(GetImportAddScreen::class)->summary($importId, self::PLAYER)->toAdd());

        // The check is not asked for again, and nothing calls the sheet unsure any more
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertNotSame('data_import_sheet', $crawler->filter('[data-testid="import-flow-primary"]')->attr('data-route'));
        self::assertStringNotContainsString('We are not sure', $crawler->filter('[data-testid="import-flow-lines"]')->text());
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/columns?summary=1');
        self::assertSame(['ok', 'ok'], $crawler->filter('[data-testid="check-sheet"]')->each(static fn ($node): null|string => $node->attr('data-mark')));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $log);
        self::assertCount(0, $crawler->filter('[data-testid="import-sheet-check-first"]'));
        self::assertCount(0, $crawler->filter('input[name="answers[check_columns]"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-column-guess"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-column-card"]'));

        // ---- "Start over for this sheet" is not a confirmation: the fresh guess asks for the check again ----
        $this->bus->dispatch(new StartImportSheetOver($importId, self::PLAYER, $log, 2));
        $this->workImportsQueue();
        self::assertSame(['check_columns'], array_column($this->mapping($log)['questions'], 'type'));
        self::assertSame([3, 3, 0, 0, 6], array_values($this->standing($log)));
        self::assertSame(0, $this->rowsWhere('sheet_id = :sheet AND puzzle_key IS NOT NULL', $log));
    }

    public function testAfterTheCheckThePlayerGoesOnToTheNextSheetThatAsks(): void
    {
        $importId = $this->uploaded(twoLogs: true);
        $log = $this->sheet($importId, 'Log');
        $older = $this->sheet($importId, 'Older');
        self::assertSame(['check_columns'], array_column($this->mapping($older)['questions'], 'type'));

        TestingLogin::asPlayer($this->browser, self::PLAYER);

        // "Where you are" speaks of sheets of the file, not of "your file", when it has several
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertStringContainsString('2 sheets of your file have no header row', $crawler->filter('[data-testid="import-flow-lines"]')->text());

        // The Puzzles page of an import whose puzzles all wait for the check: it says so and leads there - never
        // "We matched 0 of your 0 puzzles" with a "Continue"
        $this->database->executeStatement("UPDATE data_import_sheet SET kind = 'not_puzzles', mapping = jsonb_set(mapping, '{kind}', '\"not_puzzles\"') WHERE import_id = :id AND title = 'Results'", ['id' => $importId]);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('2 sheets wait for you to check their columns', $crawler->filter('[data-testid="import-puzzles-held"]')->text());
        self::assertNotNull($crawler->filter('[data-testid="import-puzzles-count"]')->attr('hidden'));
        self::assertSame('/en/import/' . $importId . '/sheets/' . $log, $crawler->filter('[data-testid="import-puzzles-check-first"]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="import-flow-continue"]'));

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $log);
        self::assertStringContainsString('Sheet 1 of 2 to check', $crawler->filter('[data-testid="import-sheet-check-position"]')->text());
        $this->browser->submit($crawler->filter('[data-testid="import-sheet-check-yes"]')->form());
        $this->assertResponseRedirects('/en/import/' . $importId . '/sheets/' . $older);
        $this->workImportsQueue();

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $older);
        $this->browser->submit($crawler->filter('[data-testid="import-sheet-check-yes"]')->form());
        $this->assertResponseRedirects('/en/import/' . $importId);
    }

    public function testAResultsSheetWithoutATimeColumnSaysSoBeforeThePlayerConfirms(): void
    {
        $importId = $this->uploaded(withTimes: false);
        $log = $this->sheet($importId, 'Log');
        self::assertNotContains('time', array_column($this->mapping($log)['columns'], 'field'));

        TestingLogin::asPlayer($this->browser, self::PLAYER);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $log);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString(
            'We did not find a column with your times. Pick it below - otherwise your puzzles are added without a time.',
            $crawler->filter('[data-testid="import-sheet-check-first"] [data-testid="import-sheet-no-time"]')->text(),
        );

        // Confirmed as it is: "Check and add" says that these results have no time - before anything is added
        $this->browser->submit($crawler->filter('[data-testid="import-sheet-check-yes"]')->form());
        $this->workImportsQueue();
        $this->buildPuzzles($importId);
        $this->database->executeStatement("UPDATE data_import_puzzle SET choice = 'unlisted' WHERE import_id = :id", ['id' => $importId]);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        $this->assertResponseIsSuccessful();
        self::assertSame('6', $crawler->filter('[data-testid="import-add-no-time"]')->attr('data-rows'));
        self::assertStringContainsString('6 results have no time - they are added without one.', $crawler->filter('[data-testid="import-add-no-time"]')->text());
        // Nothing is read right now: the page has no live line, and no sheet is "not set up"
        self::assertCount(0, $crawler->filter('[data-testid="import-setup-live"]'));

        // ---- While a sheet is read again: the page follows by itself, and that sheet is not "not set up" ----
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = NULL WHERE id = :id', ['id' => $log]);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertSame('true', $crawler->filter('[data-testid="import-setup-live"]')->attr('data-data-import-setup-reload-page-value'));
        self::assertCount(1, $crawler->filter('[data-testid="import-add-reading-again"]'));
        self::assertCount(0, $crawler->filter('[data-testid="not-ready-sheets"]'));

        // ... and the Puzzles page shows the reading, not "We matched 0 of your 0 puzzles"
        $this->database->executeStatement('DELETE FROM data_import_puzzle WHERE import_id = :id', ['id' => $importId]);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles');
        self::assertNotNull($crawler->filter('[data-testid="import-puzzles-count"]')->attr('hidden'));
        self::assertNull($crawler->filter('[data-testid="import-setup-live"]')->attr('hidden'));
        // ... and "Where you are" waits with its heading and with step 5
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertStringContainsString('We are reading your file', $crawler->filter('[data-testid="import-flow-headline"]')->text());
        self::assertStringContainsString('Nothing is ready yet - your rows are still being read.', $crawler->filter('[data-testid="import-flow-lines"]')->text());
    }

    public function testOnlyApprovedBrandsMakeAColumnTheBrandAndTheCatalogueIsReadOnce(): void
    {
        $resolver = self::getContainer()->get(ImportBrandResolver::class);
        self::assertSame(['Trefl', 'ravensburger'], array_keys($resolver->resolveApproved(['Trefl', 'Unknown Brand', 'ravensburger', 'Nobody Makes These'])));
        // The matching of an import's puzzles still knows every brand of the catalogue
        self::assertArrayHasKey('Unknown Brand', $resolver->resolve(['Unknown Brand']));

        // One read of the brands for the guess, however often it is asked - until the service is reset (a new request
        // or message)
        $this->database->executeStatement("UPDATE manufacturer SET approved = TRUE WHERE name = 'Unknown Brand'");
        self::assertSame([], $resolver->resolveApproved(['Unknown Brand']));
        $resolver->reset();
        self::assertArrayHasKey('Unknown Brand', $resolver->resolveApproved(['Unknown Brand']));
        $this->database->executeStatement("UPDATE manufacturer SET approved = FALSE WHERE name = 'Unknown Brand'");
    }

    public function testOnlyThePlayersWordOnTheColumnsReleasesTheSheet(): void
    {
        $importId = $this->uploaded();
        $log = $this->sheet($importId, 'Log');

        // Something else said about the sheet - who solved its rows - is not a look at its columns: still held
        $this->bus->dispatch(new ConfirmImportMapping($importId, self::PLAYER, $log, 1, sheetWide: ['solvingType' => 'solo']));
        $this->workImportsQueue();
        self::assertSame(['check_columns'], array_column($this->mapping($log)['questions'], 'type'));
        self::assertSame([2, 2, 0, 0, 6], array_values($this->standing($log)));
        self::assertSame(0, $this->rowsWhere('sheet_id = :sheet AND puzzle_key IS NOT NULL', $log));

        // A column changed and saved from the sheet's page is: the artist's column is no brand
        TestingLogin::asPlayer($this->browser, self::PLAYER);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $log);
        $form = $crawler->filter('[data-testid="import-sheet-save"]')->form();
        $form['columns[2]'] = '';
        $this->browser->submit($form);
        $this->assertResponseRedirects('/en/import/' . $importId);
        $this->workImportsQueue();

        $mapping = $this->mapping($log);
        self::assertSame([], $mapping['questions']);
        self::assertSame([null, 'certain', 'player'], [$mapping['columns'][1]['field'], $mapping['columns'][1]['certainty'], $mapping['columns'][1]['reason']]);
        self::assertSame([3, 3, 6, 0, 0], array_values($this->standing($log)));
        self::assertSame(6, $this->rowsWhere('sheet_id = :sheet AND puzzle_key IS NOT NULL AND problems IS NULL', $log));
    }

    /**
     * A workbook of two sheets: "Log" without a header row (a date as text, a brand, pieces, a name, a time) and
     * "Results" under headers everybody knows, without a brand.
     */
    private function uploaded(bool $twoLogs = false, bool $withTimes = true): string
    {
        $text = RawXlsx::inline(...);
        $time = static fn (int $seconds): string => RawXlsx::n((string) ($seconds / 86400), 1);
        $day = static fn (string $date): string => RawXlsx::n((string) (intdiv((int) (new DateTimeImmutable($date . ' UTC'))->format('U'), 86400) + 25569), 2);
        $log = [];

        foreach (
            [
            ['03.01.2024', 'Ravensburger', 1000, 'Zq Check Harbour at Dusk', 4250],
            ['14.01.2024', 'Trefl', 500, 'Zq Check Paper Kites over the Bay', 2105],
            ['02.02.2024', 'Ravensburger', 1000, 'Zq Check The Old Mill by the Stream', 3880],
            ['19.02.2024', 'Ravensburger', 500, 'Zq Check Lanterns in the Rain', 1990],
            ['07.03.2024', 'Trefl', 1000, 'Zq Check Market Day in Spring', 4410],
            ['21.03.2024', 'Ravensburger', 2000, 'Zq Check A Quiet Winter Orchard', 9120],
            ] as $index => [$date, $brand, $pieces, $name, $seconds]
        ) {
            $log[$index + 1] = ['A' => $text($date), 'B' => $text($brand), 'C' => RawXlsx::n((string) $pieces), 'D' => $text($name)] + ($withTimes ? ['E' => $time($seconds)] : []);
        }

        $results = [1 => ['A' => $text('Puzzle'), 'B' => $text('Pieces'), 'C' => $text('Date'), 'D' => $text('Time')]];

        foreach ([['Zq Check Canal', 500, '2025-01-10', 2893], ['Zq Check Meadow', 1000, '2025-02-08', 5750], ['Zq Check Orchard', 500, '2025-03-01', 2411]] as $index => [$name, $pieces, $date, $seconds]) {
            $results[$index + 2] = ['A' => $text($name), 'B' => RawXlsx::n((string) $pieces), 'C' => $day($date), 'D' => $time($seconds)];
        }

        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);
        $workbook = (new RawXlsx())
            ->styles(['[h]:mm:ss', 'yyyy-mm-dd'])
            ->sheet('Log', RawXlsx::worksheet($log))
            ->sheet('Results', RawXlsx::worksheet($results));

        if ($twoLogs) {
            // A second sheet without a header row
            $workbook->sheet('Older', RawXlsx::worksheet($log));
        }

        $workbook->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        $importId = Uuid::uuid7()->toString();
        $this->bus->dispatch(new StartImport($importId, self::PLAYER, 'Log.xlsx', strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey(self::PLAYER, $importId, DataImportFileKind::Xlsx), $content);
        $this->bus->dispatch(new ConfirmImportUploaded($importId, self::PLAYER));
        $this->workImportsQueue();

        return $importId;
    }

    private function buildPuzzles(string $importId): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::getContainer()->get(BuildImportPuzzlesHandler::class)(new BuildImportPuzzles($importId));
        $entityManager->flush();
        $entityManager->clear();
        $this->queue()->reset();
    }

    /**
     * @return list<object> what waits on the imports queue, without the pairing's own messages
     */
    private function queuedMessages(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->queue()->getSent()),
            static fn (object $message): bool => $message instanceof BuildImportPuzzles === false,
        ));
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function sheet(string $importId, string $title): string
    {
        $id = $this->database->fetchOne('SELECT id FROM data_import_sheet WHERE import_id = :id AND title = :title', ['id' => $importId, 'title' => $title]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array{kindCertainty: string, kindCertainty: string, regions: list<array<string, mixed>>, columns: list<array{column: int, field: null|string, certainty: string, reason: string}>, questions: list<array<string, mixed>>}
     */
    private function mapping(string $sheetId): array
    {
        $json = $this->database->fetchOne('SELECT mapping FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]);
        self::assertIsString($json);

        /** @var array{kindCertainty: string, regions: list<array<string, mixed>>, columns: list<array{column: int, field: null|string, certainty: string, reason: string}>, questions: list<array<string, mixed>>} $mapping */
        $mapping = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $mapping;
    }

    /**
     * @return array{mapping_version: int, parsed_version: null|int, rows_understood: int, rows_need_look: int, rows_waiting: int}
     */
    private function standing(string $sheetId): array
    {
        /** @var array{mapping_version: int, parsed_version: null|int, rows_understood: int, rows_need_look: int, rows_waiting: int} $row */
        $row = $this->database->fetchAssociative('SELECT mapping_version, parsed_version, rows_understood, rows_need_look, rows_waiting FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]);

        return $row;
    }

    private function rowsWhere(string $where, string $sheetId): int
    {
        $rows = $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE ' . $where, ['sheet' => $sheetId]);
        self::assertIsInt($rows);

        return $rows;
    }

    /**
     * @return list<string> the names of the import's puzzles
     */
    private function puzzles(string $importId): array
    {
        /** @var list<string> $names */
        $names = $this->database->fetchFirstColumn('SELECT name FROM data_import_puzzle WHERE import_id = :id ORDER BY name', ['id' => $importId]);

        return $names;
    }
}
