<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Slice 2 of the import's guided flow (docs/features/data-import/guided-flow.md §5.9, §7): "Check and add" says how
 * every sheet was read before the button, and "What will be added" lists exactly the rows an "Add" then queues -
 * each with "Don't import" (the row's own "leave it out", AnswerImportRows) and "Put back", for a row, for every
 * row of a puzzle and for a whole sheet. The pages are driven as a browser drives them; the worker's turns are taken
 * by hand. Every puzzle, person and row here is made up.
 */
final class ImportAddListTest extends WebTestCase
{
    use QueryCountAssertions;
    use ImportAddingScenario;
    use ImportedWorkbookScenario;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheListIsExactlyWhatAnAddThenQueues(): void
    {
        $player = $this->seedPairingPlayer('listqueue');
        $brand = $this->seedBrand('Zqlist');
        $harbour = $this->seedCataloguePuzzle('Zq List Harbour', 500, $brand);
        $this->seedCataloguePuzzle('Zq List Meadow', 1000, $brand);
        $this->seedCataloguePuzzle('Zq List Orchard', 750, $brand);
        $this->seedCataloguePuzzle('Zq List Wish', 2000, $brand);
        $this->seedCataloguePuzzle('Zq List Twin', 500, $this->seedBrand('Zqlistone'));
        $this->seedCataloguePuzzle('Zq List Twin', 500, $this->seedBrand('Zqlisttwo'));
        // A result typed by hand before the file came: its row adds nothing, and is queued all the same
        $this->typedResult($player, $harbour, '2020-01-10', 6000);

        [$import, $results] = $this->importOf($player);
        $collection = $this->sheet($import, 1, 'Collection');
        $expected = [];

        for ($row = 0; $row < 52; $row++) {
            $expected[] = $this->resultRow($import, $results, 'Zq List Harbour', 500, 'Zqlist', (new DateTimeImmutable('2023-01-01'))->modify('+' . $row . ' days')->format('Y-m-d'), 3000 + $row);
        }

        $expected[] = $known = $this->resultRow($import, $results, 'Zq List Harbour', 500, 'Zqlist', '2020-01-10', 6000);
        $expected[] = $both = $this->resultRow($import, $results, 'Zq List Meadow', 1000, 'Zqlist', '2021-03', 5000, kind: DataImportRowKind::Both);
        $standing = $this->resultRow($import, $results, 'Zq List Meadow', 1000, 'Zqlist', '2024-04-04', 4000);
        $repeat = $this->resultRow($import, $results, 'Zq List Meadow', 1000, 'Zqlist', '2024-04-04', 4000, ['occurrence' => 2]);
        $this->database->update('data_import_row', ['same_as_row_id' => $standing], ['id' => $repeat]);
        array_push($expected, $standing, $repeat);
        // Not ready: a result without any date, and a puzzle two catalogue puzzles fit
        $this->seedRow($import, $results, 'Zq List Meadow', 1000, 'Zqlist', ['seconds' => 4100, 'type' => 'solo']);
        $this->resultRow($import, $results, 'Zq List Twin', 500, '', '2024-05-05', 2000);
        // A puzzle nobody knows, kept just for the player: its row is taken, nothing of it is public
        $expected[] = $unlisted = $this->resultRow($import, $results, 'Zq List Nobody Knows Qxv', 500, '', '2024-05-06', 2100);
        $expected[] = $owned = $this->ownedRow($import, $collection, 'Zq List Orchard', 750, 'Zqlist');
        $expected[] = $wish = $this->wishRow($import, $collection, 'Zq List Wish', 2000, 'Zqlist');
        $this->pair($import);
        $importId = $import->id->toString();
        $this->database->update('data_import_puzzle', ['choice' => 'unlisted'], ['id' => $this->importPuzzle($importId, 'Zq List Nobody Knows Qxv')['id']]);
        $this->sheetsAreRead($importId);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $path = '/en/import/' . $importId . '/add/list';

        // ---- The list, fifty a page, in the file's order, under each sheet's name ----
        $crawler = $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());
        $list = $crawler->filter('[data-testid="import-add-list"]');
        self::assertSame(['59', '59'], [$list->attr('data-total'), $list->attr('data-rows-taken')]);
        self::assertCount(50, $crawler->filter('[data-testid="import-add-list-row"]'));
        self::assertSame($path . '?page=2', $crawler->filter('[data-testid="import-pages-next"]')->attr('href'));
        $second = $this->browser->request('GET', $path . '?page=2');
        self::assertCount(9, $second->filter('[data-testid="import-add-list-row"]'));
        $listed = [...self::rowIds($crawler), ...self::rowIds($second)];
        self::assertSame($expected, $listed, 'every row an "Add" would take, in the order it takes them');
        // A page that is not there: the first one
        $this->browser->request('GET', $path . '?page=9');
        self::assertResponseRedirects($path);

        // Each sheet under its name with its number of rows
        self::assertSame(['“Results” - 57 rows'], $crawler->filter('[data-testid="import-add-list-sheet-heading"] h2')->each(static fn (Crawler $heading): string => self::spaced($heading->text())));
        self::assertSame(['“Results” - 57 rows', '“Collection” - 2 rows'], $second->filter('[data-testid="import-add-list-sheet-heading"] h2')->each(static fn (Crawler $heading): string => self::spaced($heading->text())));

        // A row: the puzzle with its picture's place, time, date, added as - and what it would not add, said
        $first = $crawler->filter('[data-testid="import-add-list-row"]')->eq(0);
        self::assertSame('ready', $first->attr('data-state'));
        self::assertSame('Zq List Harbour', self::spaced($first->filter('[data-testid="add-row-puzzle"]')->text()));
        self::assertSame('0:50:00 · Jan 1, 2023 · solo', self::spaced($first->filter('[data-testid="add-row-said"]')->text()));
        self::assertSame("Don't import this row", $first->filter('[data-testid="import-add-list-leave-out"]')->attr('aria-label'));
        self::assertSame('On your profile already - not added twice', self::note($second, $known));
        self::assertSame('+ collection', self::note($second, $both));
        // A day known to the month only is added as the month - explained on the row
        self::assertMatchesRegularExpression('/^1:23:20 · Mar(ch)? 2021 · solo/', self::spaced(self::row($second, $both)->filter('[data-testid="add-row-said"]')->text()));
        self::assertStringContainsString('The result is added as “March 2021”', self::row($second, $both)->filter('[data-testid="import-explain-month-only"]')->text());
        self::assertSame('Repeats another row - counted once', self::note($second, $repeat));
        self::assertSame('Kept just for you', self::note($second, $unlisted));
        // Nothing of the file is in an address
        self::assertSame([], array_values(array_filter(
            [...$crawler->filter('[data-testid="import-add-list-page"] a')->each(static fn (Crawler $link): string => (string) $link->attr('href')), ...$crawler->filter('form')->each(static fn (Crawler $form): string => (string) $form->attr('action'))],
            static fn (string $address): bool => str_contains($address, 'Zq') || str_contains($address, 'Harbour'),
        )));

        // ---- The bar and the tabs say what the review says ----
        $review = $this->browser->request('GET', '/en/import/' . $importId . '/add')->filter('[data-testid="import-add-counts"]');
        self::assertSame(['59', '54', '2', '1'], [$review->attr('data-rows'), $review->attr('data-results'), $review->attr('data-owned'), $review->attr('data-wish-list')]);
        self::assertSame('58 will be added', self::spaced($crawler->filter('[data-testid="import-add-list-will-add"]')->text()));
        self::assertSame(
            [['all', '59'], ['results', '54'], ['collection', '2'], ['wish', '1']],
            $crawler->filter('[data-testid="import-add-list-tab"]')->each(static fn (Crawler $tab): array => [$tab->attr('data-makes'), $tab->attr('data-count')]),
        );
        self::assertSame([$both, $owned], self::rowIds($this->browser->request('GET', $path . '?makes=collection')));
        self::assertSame([$wish], self::rowIds($this->browser->request('GET', $path . '?makes=wish')));
        self::assertCount(50, $this->browser->request('GET', $path . '?makes=results')->filter('[data-testid="import-add-list-row"]'));
        self::assertSame('54', $this->browser->getCrawler()->filter('[data-testid="import-add-list"]')->attr('data-total'));

        // ---- By sheet, and by a part of the puzzle's name - the player's own text, kept in the address ----
        $collectionId = $collection->id->toString();
        self::assertSame([$owned, $wish], self::rowIds($this->browser->request('GET', $path . '?sheet=' . $collectionId)));
        $found = $this->browser->request('GET', $path . '?q=MEADOW');
        self::assertSame([$both, $standing, $repeat], self::rowIds($found));
        self::assertSame('MEADOW', $found->filter('[data-testid="import-add-list-q"]')->attr('value'));
        // The catalogue's name is searched like everywhere else: without its accents and marks
        self::assertSame([$owned], self::rowIds($this->browser->request('GET', $path . '?q=' . rawurlencode('örchard'))));
        self::assertSame("No row with that puzzle.", self::spaced($this->browser->request('GET', $path . '?q=nothing-like-this')->filter('[data-testid="import-add-list-none"]')->text()));
        // Whatever else the address says is not listened to
        self::assertSame($expected, [...self::rowIds($this->browser->request('GET', $path . '?makes=everything&sheet=not-a-sheet')), ...self::rowIds($second)]);

        // ---- "Add": the engine queues exactly the rows the list showed ----
        $batchId = (string) $this->add($importId, $player)->id;
        $queued = $this->database->fetchFirstColumn(
            'SELECT r.id FROM data_import_row r INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id WHERE r.batch_id = :batchId ORDER BY sheet.position, r.row_number, r.id',
            ['batchId' => $batchId],
        );
        self::assertSame($listed, $queued);
        self::assertSame(59, $this->batchNow($batchId)['rows_requested']);
    }

    public function testDontImportAndPutBackForARowAPuzzleAndASheetAndTheReviewFollows(): void
    {
        $player = $this->seedPairingPlayer('listuntick');
        $puzzles = [
            'Zq Untick Harbour' => $this->seedCataloguePuzzle('Zq Untick Harbour', 500, $this->seedBrand('Zquntick')),
            'Zq Untick Meadow' => $this->seedCataloguePuzzle('Zq Untick Meadow', 1000, $this->seedBrand('Zquntickmeadow')),
        ];
        $importId = $this->importedSheets($player, [
            'Solo 500' => [
                ['Zq Untick Harbour', 500, '2023-03-01', 3000],
                ['Zq Untick Meadow', 1000, '2023-03-02', 5000],
                ['Zq Untick Harbour', 500, '2023-03-03', 3100],
            ],
            'Older' => [
                ['Zq Untick Harbour', 500, '2022-03-01', 3200],
                ['Zq Untick Meadow', 1000, '2022-03-02', 5100],
            ],
        ]);
        $this->matched($importId, $puzzles);
        $playerId = $player->id->toString();
        TestingLogin::asPlayer($this->browser, $playerId);
        $path = '/en/import/' . $importId . '/add/list';
        $meadow = $this->rowIdOn($importId, 'Solo 500', 3);

        $crawler = $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('[data-testid="import-add-list-row"][data-state="ready"]'));
        self::assertSame('5 will be added', self::spaced($crawler->filter('[data-testid="import-add-list-will-add"]')->text()));
        self::assertStringContainsString('The rows stay in your file here and are not added. You can put them back at any time.', $crawler->filter('[data-testid="import-explain-dont-import"]')->text());
        $token = (string) $crawler->filter('[data-testid="import-add-list-row"] input[name="_token"]')->first()->attr('value');
        // The review's example says what the file says, beside what it is added as
        $review = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertCount(2, $review->filter('[data-testid="import-add-example"]'));
        self::assertStringStartsWith('Your file says “', self::spaced($review->filter('[data-testid="import-add-example-said"]')->first()->text()));

        // ---- One row: "Don't import" - a small POST, answered by the same list ----
        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'leave_out', 'row' => $meadow, 'page' => 1]);
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($path . '?page=1#row-' . $meadow);
        // The existing state of a row that is left out - no second one. It is written in the request itself (the row
        // is read again there, no worker): from the answer on, no "Add" can take the row
        self::assertSame(4, self::getContainer()->get(GetImportAddScreen::class)->summary($importId, $playerId)->rowsReady);
        self::assertSame(['left_out', 'by_player', 'true'], $this->database->fetchNumeric("SELECT state, parsed ->> 'leftOut', answers ->> 'leftOut' FROM data_import_row WHERE id = :id", ['id' => $meadow]));
        $this->matched($importId, $puzzles);
        $crawler = $this->browser->request('GET', $path);
        self::assertSame('Not imported. “Put back” brings the row back.', self::spaced($crawler->filter('[data-testid="import-add-list-flash"]')->text()));
        self::assertCount(4, $crawler->filter('[data-testid="import-add-list-row"][data-state="ready"]'));
        // It stays in its place in the file, with what its cells say, and "Put back"
        self::assertSame(['ready', 'not-imported', 'ready', 'ready', 'ready'], $crawler->filter('[data-testid="import-add-list-row"]')->each(static fn (Crawler $row): null|string => $row->attr('data-state')));
        $leftOut = self::row($crawler, $meadow);
        self::assertStringContainsString('Zq Untick Meadow', self::spaced($leftOut->filter('[data-testid="import-add-list-file-says"]')->text()));
        self::assertSame('Not imported', self::spaced($leftOut->filter('[data-testid="import-add-list-not-imported"]')->text()));
        self::assertSame('Put back', self::spaced($leftOut->filter('[data-testid="import-add-list-put-back"]')->text()));
        self::assertSame('4 will be added', self::spaced($crawler->filter('[data-testid="import-add-list-will-add"]')->text()));
        // The review's counts follow, and "Rows we could not read" lists the row among the ones not imported
        self::assertSame(['4', '4'], $this->reviewCounts($importId));
        self::assertCount(1, $this->browser->request('GET', '/en/import/' . $importId . '/to-fix')->filter('[data-testid="import-to-fix-left-out-row"]'));

        // ---- "Put back" ----
        $rowsBefore = self::rowIds($crawler);
        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'put_back', 'row' => $meadow]);
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($path . '?page=1&back=' . $meadow . '#row-' . $meadow);
        self::assertSame(['waiting', null], $this->database->fetchNumeric("SELECT state, answers ->> 'leftOut' FROM data_import_row WHERE id = :id", ['id' => $meadow]));
        // Its puzzle is matched again in a moment (the worker): until then the row stands IN ITS PLACE as "coming
        // back" - it never vanishes -, the count is what an Add would take now, and the page asks again by itself
        $crawler = $this->browser->followRedirect();
        self::assertStringStartsWith('Put back.', self::spaced($crawler->filter('[data-testid="import-add-list-flash"]')->text()));
        self::assertSame($rowsBefore, self::rowIds($crawler), 'the same rows, in the same order');
        $coming = self::row($crawler, $meadow);
        self::assertSame('coming-back', $coming->attr('data-state'));
        self::assertNotNull($coming->attr('data-import-coming-back'));
        self::assertStringStartsWith('Put back.', self::spaced($coming->filter('[data-testid="import-add-list-coming-back"]')->text()));
        self::assertCount(0, $coming->filter('button'));
        self::assertCount(4, $crawler->filter('[data-testid="import-add-list-row"][data-state="ready"]'));
        // The same address again once its puzzle is matched: the row as it was, ticked, and the count with it
        $this->matched($importId, $puzzles);
        $again = $this->browser->request('GET', $path . '?page=1&back=' . $meadow);
        self::assertSame($rowsBefore, self::rowIds($again));
        self::assertSame('ready', self::row($again, $meadow)->attr('data-state'));
        self::assertCount(0, $again->filter('[data-import-coming-back]'));
        self::assertCount(5, $again->filter('[data-testid="import-add-list-row"][data-state="ready"]'));
        // A row that is not put back is never shown as coming back by its id in an address
        self::assertCount(0, $this->browser->request('GET', $path . '?back=' . $this->rowIdOn($importId, 'Older', 2))->filter('[data-import-coming-back]'));
        $crawler = $this->browser->request('GET', $path);
        self::assertCount(5, $crawler->filter('[data-testid="import-add-list-row"][data-state="ready"]'));
        self::assertSame(['5', '5'], $this->reviewCounts($importId));

        // ---- Every row of one puzzle: offered where a search narrowed the list to it ----
        self::assertCount(0, $crawler->filter('[data-testid="import-add-list-puzzle"]'), 'not on a list of several puzzles');
        $crawler = $this->browser->request('GET', $path . '?q=harbour');
        $onePuzzle = $crawler->filter('[data-testid="import-add-list-puzzle"]');
        self::assertSame('3', $onePuzzle->attr('data-rows'));
        self::assertSame("Don't import any of this puzzle (3 rows)", self::spaced($onePuzzle->filter('[data-testid="import-add-list-puzzle-leave-out"]')->text()));
        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'leave_out_puzzle', 'puzzle' => (string) $onePuzzle->filter('input[name="puzzle"]')->attr('value'), 'q' => 'harbour']);
        self::assertResponseRedirects($path . '?q=harbour&page=1');
        self::assertSame(3, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state = 'left_out' AND parsed ->> 'leftOut' = 'by_player'", ['id' => $importId]));
        $this->matched($importId, $puzzles);
        // Under the same search the three rows are still there - not imported, each with its "Put back"
        $crawler = $this->browser->request('GET', $path . '?q=harbour');
        self::assertSame('3 rows are not imported. You can put each of them back.', self::spaced($crawler->filter('[data-testid="import-add-list-flash"]')->text()));
        self::assertSame(['not-imported', 'not-imported', 'not-imported'], $crawler->filter('[data-testid="import-add-list-row"]')->each(static fn (Crawler $row): null|string => $row->attr('data-state')));
        self::assertCount(3, $crawler->filter('[data-testid="import-add-list-put-back"]'));
        self::assertSame(['2', '2'], $this->reviewCounts($importId));
        $harbour = $this->rowIdOn($importId, 'Older', 2);
        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'put_back', 'row' => $harbour, 'q' => 'harbour']);
        self::assertResponseRedirects($path . '?q=harbour&page=1&back=' . $harbour . '#row-' . $harbour);
        $this->matched($importId, $puzzles);
        self::assertSame(['3', '3'], $this->reviewCounts($importId));

        // ---- A whole sheet: asked by its name and number first, then "not puzzles" - changed back in step 2 ----
        $crawler = $this->browser->request('GET', $path);
        $older = $crawler->filter('[data-testid="import-add-list-sheet-heading"]')->eq(1);
        self::assertSame('“Older” - 2 rows', self::spaced($older->filter('h2')->text()));
        $sheet = $older->filter('[data-testid="import-add-list-sheet-leave-out"]');
        self::assertStringContainsString("Don't import the 2 rows of “Older”? You can change this in step 2.", self::spaced($sheet->text()));
        $sheetId = (string) $sheet->filter('input[name="sheet"]')->attr('value');
        // Every "Don't import" / "Put back" of the list swaps the list's frame only - the page stays where it is
        self::assertSame('import-add-list', $sheet->filter('form')->attr('data-turbo-frame'));
        self::assertCount(0, $crawler->filter('turbo-frame#import-add-list form[method="post"]:not([data-turbo-frame="import-add-list"])'));
        $version = (int) $sheet->filter('input[name="version"]')->attr('value');

        // Changed meanwhile (another tab changed the sheet): nothing is saved, said plainly
        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'leave_out_sheet', 'sheet' => $sheetId, 'version' => $version - 1]);
        self::assertResponseRedirects($path . '?page=1');
        self::assertSame('This changed in the meantime - here is how it stands now. Nothing was saved.', self::spaced($this->browser->request('GET', $path)->filter('[data-testid="import-add-list-flash"]')->text()));
        self::assertSame('results', $this->database->fetchOne('SELECT kind FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]));

        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'leave_out_sheet', 'sheet' => $sheetId, 'version' => $version]);
        self::assertResponseRedirects($path . '?page=1');
        self::assertSame('not_puzzles', $this->database->fetchOne('SELECT kind FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]));
        // "Don't import this sheet" holds from the moment it is said: its rows are read again in the background, and
        // until then none of them is ready. What the engine's own summary says, and an "Add" pressed right now -
        // BEFORE the worker ran - takes the other sheet's row only
        $olderRows = array_values(array_filter($this->database->fetchFirstColumn('SELECT id FROM data_import_row WHERE sheet_id = :id', ['id' => $sheetId]), is_string(...)));
        self::assertSame(['waiting', 'waiting'], $this->database->fetchFirstColumn("SELECT state FROM data_import_row WHERE sheet_id = :id AND kind = 'result' ORDER BY row_number", ['id' => $sheetId]), 'not read again yet');
        $summary = self::getContainer()->get(GetImportAddScreen::class)->summary($importId, $playerId);
        self::assertSame([1, 1, 1], [$summary->rowsReady, $summary->results, $summary->sheetsNotSetUp]);
        $early = (string) $this->add($importId, $player)->id;
        $taken = array_values(array_filter($this->database->fetchFirstColumn('SELECT id FROM data_import_row WHERE batch_id = :batchId', ['batchId' => $early]), is_string(...)));
        self::assertCount(1, $taken);
        self::assertSame([], array_values(array_intersect($taken, $olderRows)), 'no row of the sheet that is not imported is queued');
        // (the row goes back to waiting: this test goes on with it)
        $this->database->executeStatement("UPDATE data_import_row SET state = 'waiting', batch_id = NULL WHERE batch_id = :batchId", ['batchId' => $early]);
        $this->database->executeStatement('DELETE FROM data_import_batch WHERE id = :batchId', ['batchId' => $early]);

        // The worker reads the sheet again: its rows are no puzzles - and what is ready is what it was
        $this->workImportsQueue();
        $this->matched($importId, $puzzles);
        $crawler = $this->browser->request('GET', $path);
        self::assertCount(0, $crawler->filter('[data-testid="import-add-list-reading-again"]'));
        self::assertSame(['“Solo 500” - 1 row'], $crawler->filter('[data-testid="import-add-list-sheet-heading"] h2')->each(static fn (Crawler $heading): string => self::spaced($heading->text())));
        self::assertSame(['1', '1'], $this->reviewCounts($importId));

        // ---- And the "Add" takes what is left: the one row the list still ticks ----
        $ticked = $crawler->filter('[data-testid="import-add-list-row"][data-state="ready"]')->each(static fn (Crawler $row): string => (string) $row->attr('data-row-id'));
        $batchId = (string) $this->add($importId, $player)->id;
        self::assertEqualsCanonicalizing($ticked, $this->database->fetchFirstColumn('SELECT id FROM data_import_row WHERE batch_id = :batchId', ['batchId' => $batchId]));
        self::assertCount(1, $ticked);

        // A sheet whose columns were changed waits for its re-read the same way: none of its rows is listed, counted
        // or taken - the list and the review say "being read again"
        $second = $this->importedSheets($player, ['Later' => [['Zq Untick Harbour', 500, '2024-03-01', 3300], ['Zq Untick Meadow', 1000, '2024-03-02', 5200]]]);
        $this->matched($second, $puzzles);
        self::assertSame(2, self::getContainer()->get(GetImportAddScreen::class)->summary($second, $playerId)->rowsReady);
        $this->database->executeStatement('UPDATE data_import_sheet SET mapping_version = mapping_version + 1 WHERE import_id = :id', ['id' => $second]);
        $crawler = $this->browser->request('GET', '/en/import/' . $second . '/add/list');
        self::assertSame('A sheet is being read again - a moment. Its rows are not counted here until it is read. Refresh', self::spaced($crawler->filter('[data-testid="import-add-list-reading-again"]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="import-add-list-row"]'));
        $review = $this->browser->request('GET', '/en/import/' . $second . '/add');
        self::assertCount(1, $review->filter('[data-testid="import-add-reading-again"]'));
        self::assertSame('0', $review->filter('[data-testid="import-add-counts"]')->attr('data-rows'));
        self::assertCount(0, $review->filter('[data-testid="import-add-form"]'), 'no Add button while nothing is ready');
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND batch_id IS NOT NULL', ['id' => $second]));
    }

    public function testASheetThatWasJustLeftOutIsPutBackFromTheListsBar(): void
    {
        $player = $this->seedPairingPlayer('listsheetback');
        $puzzles = [
            'Zq Sheetback Harbour' => $this->seedCataloguePuzzle('Zq Sheetback Harbour', 500, $this->seedBrand('Zqsheetback')),
            'Zq Sheetback Meadow' => $this->seedCataloguePuzzle('Zq Sheetback Meadow', 1000, $this->seedBrand('Zqsheetbackmeadow')),
        ];
        $importId = $this->importedSheets($player, [
            'Solo 500' => [
                ['Zq Sheetback Harbour', 500, '2023-03-01', 3000],
                ['Zq Sheetback Meadow', 1000, '2023-03-02', 5000],
            ],
            'Older' => [
                ['Zq Sheetback Harbour', 500, '2022-03-01', 3200],
                ['Zq Sheetback Meadow', 1000, '2022-03-02', 5100],
            ],
        ]);
        $this->matched($importId, $puzzles);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $path = '/en/import/' . $importId . '/add/list';

        $crawler = $this->browser->request('GET', $path);
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $sheet = $crawler->filter('[data-testid="import-add-list-sheet-heading"]')->eq(1)->filter('[data-testid="import-add-list-sheet-leave-out"]');
        self::assertSame("Yes, don't import this sheet", self::spaced($sheet->filter('[data-testid="import-add-list-sheet-confirm"]')->text()));
        $sheetId = (string) $sheet->filter('input[name="sheet"]')->attr('value');
        $kind = fn (): mixed => $this->database->fetchOne('SELECT kind FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]);

        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'leave_out_sheet', 'sheet' => $sheetId, 'version' => (string) $sheet->filter('input[name="version"]')->attr('value')]);
        self::assertSame('not_puzzles', $kind());
        $bar = $this->browser->request('GET', $path)->filter('[data-testid="import-add-list-bar"]');
        $putBack = $bar->filter('form[data-testid="import-add-list-sheet-put-back"]');
        self::assertSame('Put back', self::spaced($putBack->filter('button')->text()));
        // Shown once, with the answer it belongs to
        self::assertCount(0, $this->browser->request('GET', $path)->filter('[data-testid="import-add-list-sheet-put-back"]'));

        // A kind that is none, "not puzzles" itself, a sheet that is not left out, and no token: nothing is put back
        foreach (['nonsense', 'not_puzzles'] as $crafted) {
            $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'put_back_sheet', 'sheet' => $sheetId, 'kind' => $crafted]);
            self::assertResponseRedirects($path . '?page=1');
            self::assertSame('not_puzzles', $kind(), $crafted);
            self::assertStringContainsString('Nothing was saved.', $this->browser->request('GET', $path)->filter('[data-testid="import-add-list-flash"]')->text());
        }

        $this->browser->request('POST', $path, ['action' => 'put_back_sheet', 'sheet' => $sheetId, 'kind' => 'results']);
        self::assertResponseStatusCodeSame(403);

        // "Put back": the sheet is what it was - its rows are read again
        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'put_back_sheet', 'sheet' => $sheetId, 'kind' => 'results']);
        self::assertResponseRedirects($path . '?page=1');
        self::assertSame('results', $kind());
        $bar = $this->browser->request('GET', $path)->filter('[data-testid="import-add-list-bar"]');
        self::assertStringContainsString('Put back. Its rows are read again', $bar->filter('[data-testid="import-add-list-flash"]')->text());
        self::assertCount(0, $bar->filter('[data-testid="import-add-list-sheet-put-back"]'));
        $this->browser->request('POST', $path, ['_token' => $token, 'action' => 'put_back_sheet', 'sheet' => $sheetId, 'kind' => 'results']);
        self::assertSame('results', $kind());
    }

    public function testCheckAndAddSaysWhatEverySheetIsAddedAsAndHowItWasRead(): void
    {
        $player = $this->seedPairingPlayer('listread');
        $partner = $this->seedPairingPlayer('listreadpartner');
        $brand = $this->seedBrand('Zqread');
        $this->seedCataloguePuzzle('Zq Read Harbour', 500, $brand);
        $this->seedCataloguePuzzle('Zq Read Meadow', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Read Orchard', 750, $brand);
        $this->seedCataloguePuzzle('Zq Read Wish', 2000, $brand);

        [$import, $solo] = $this->importOf($player);
        $this->database->update('data_import_sheet', ['title' => 'Solo 500'], ['id' => $solo->id->toString()]);
        $pairs = $this->sheet($import, 1, '500 piece Pairs');
        $collection = $this->sheet($import, 2, 'Collection');
        $wishes = $this->sheet($import, 3, 'Wishes');
        $this->resultRow($import, $solo, 'Zq Read Harbour', 500, 'Zqread', '2023-04-03', 2710);
        $this->resultRow($import, $solo, 'Zq Read Harbour', 500, 'Zqread', '2021-03', 2800);
        $this->resultRow($import, $pairs, 'Zq Read Meadow', 1000, 'Zqread', '2023-05-01', 5400, ['type' => 'duo', 'people' => ['Anna']]);
        $this->resultRow($import, $pairs, 'Zq Read Meadow', 1000, 'Zqread', '2023-05-02', 5500, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->resultRow($import, $pairs, 'Zq Read Meadow', 1000, 'Zqread', '2023-05-03', 5600, ['type' => 'team', 'people' => ['Anna', 'Tom']]);
        $this->ownedRow($import, $collection, 'Zq Read Orchard', 750, 'Zqread');
        $this->wishRow($import, $wishes, 'Zq Read Wish', 2000, 'Zqread');
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        // How the first sheet's dates and times were settled - by its rows and by the player's own answer
        $this->database->update('data_import_sheet', ['options' => json_encode([
            'dateOrder' => ['order' => 'dmy', 'by' => 'rows'],
            'durations' => ['two_part_text' => ['rule' => 'minutes_seconds', 'by' => 'player']],
            'year' => ['year' => 2023, 'by' => 'player'],
        ])], ['id' => $solo->id->toString()]);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertResponseIsSuccessful();
        $sheets = $crawler->filter('[data-testid="import-add-sheet"]');
        self::assertSame(['“Solo 500”', '“500 piece Pairs”', '“Collection”', '“Wishes”'], $sheets->filter('[data-testid="import-add-sheet-title"]')->each(static fn (Crawler $title): string => self::spaced($title->text())));
        $as = static fn (int $sheet): array => $sheets->eq($sheet)->filter('[data-testid="import-add-sheet-as"] li')->each(static fn (Crawler $line): string => self::spaced($line->text()));

        // What each sheet's rows are added as - and with whom: a guest, or a player
        self::assertSame(['2 solo results'], $as(0));
        [$pairLine, $teamLine] = $as(1);
        self::assertStringStartsWith('2 pair results with ', $pairLine);
        self::assertStringStartsWith('1 team result with ', $teamLine);

        foreach (['#' . $partner->code . ' (player)', 'Anna (guest)', 'Tom (guest)'] as $person) {
            self::assertStringContainsString($person, $pairLine);
        }
        self::assertSame(['1 puzzle you own'], $as(2));
        self::assertSame(['1 puzzle for your wish list'], $as(3));
        self::assertSame('Puzzles you own go to your collection.', self::spaced($crawler->filter('[data-testid="import-add-collection"]')->text()));

        // Every reading of the sheet is a line: the date order, the times, the year, the results with a month only
        self::assertSame(
            [
                'Dates: day first - 03/04/2023 is 3 April 2023.',
                'Dates written without a year: 2023.',
                'Times are read as: Minutes and seconds',
                '1 result has only a month or a year - it is added that way.',
            ],
            $sheets->eq(0)->filter('[data-testid="import-add-reading"]')->each(static fn (Crawler $line): string => self::spaced($line->text())),
        );
        self::assertCount(0, $sheets->eq(1)->filter('[data-testid="import-add-reading"]'));
        // One row of each sheet, as it will look
        self::assertSame('0:45:10 · Apr 3, 2023 · solo', self::spaced($sheets->eq(0)->filter('[data-testid="import-add-example"] [data-testid="add-row-said"]')->text()));
        self::assertCount(4, $crawler->filter('[data-testid="import-add-example"]'));

        // The words this screen introduces are explained where they are met, in the specification's own sentences
        self::assertSame("A guest is just a name on your result - nobody's profile changes.", self::spaced($crawler->filter('[data-testid="import-add-how"] [data-testid="import-explain-guest"] p')->text()));
        self::assertSame('The result also appears on their profile, as a pair or team result with you. They are told once, and can remove themselves.', self::spaced($crawler->filter('[data-testid="import-explain-player-partner"] p')->text()));
        self::assertSame('Your file gives no exact day. The result is added as “March 2021” - it counts everywhere except in things that need a day, like the calendar and streaks.', self::spaced($crawler->filter('[data-testid="import-explain-month-only"] p')->text()));
        self::assertSame('Much faster or slower than your other results suggest for this puzzle. We ask so a typing slip does not end up on a leaderboard - if it is right, say so and it is added.', self::spaced($crawler->filter('[data-testid="import-explain-unusual-time"] p')->text()));

        // "Where you are": step 3 says who the file names - and nobody when it names nobody
        $people = $this->browser->request('GET', '/en/import/' . $importId)->filter('[data-testid="import-flow-line"][data-step="people"]');
        self::assertSame('open', $people->attr('data-state'));
        self::assertStringContainsString('Tell us who 2 people are', self::spaced($people->text()));
        $this->database->executeStatement("UPDATE data_import_person SET decided_how = 'player' WHERE import_id = :id AND decided_how = 'default'", ['id' => $importId]);
        $people = $this->browser->request('GET', '/en/import/' . $importId)->filter('[data-testid="import-flow-line"][data-step="people"]');
        self::assertSame('done', $people->attr('data-state'));
        self::assertStringContainsString('People in your file: 3 - players: 1, guests: 2', self::spaced($people->text()));

        [$nobody, $nobodyImportId] = $this->importOfRows('listnobody', 2);
        TestingLogin::asPlayer($this->browser, $nobody->id->toString());
        $people = $this->browser->request('GET', '/en/import/' . $nobodyImportId)->filter('[data-testid="import-flow-line"][data-step="people"]');
        self::assertSame(['done', 'Nobody else is named.'], [$people->attr('data-state'), self::spaced($people->filter('[data-testid="import-flow-line-text"]')->text())]);
    }

    public function testAnotherPlayersImportIsNotFoundAndEveryChangeNeedsItsToken(): void
    {
        [$owner, $importId, $rowIds] = $this->importOfRows('listowner', 2);
        [$other, $otherImportId] = $this->importOfRows('listother', 1);
        $path = '/en/import/' . $importId . '/add/list';

        // Nobody signed in: the sign-in page
        $this->browser->request('GET', $path);
        self::assertResponseStatusCodeSame(302);

        TestingLogin::asPlayer($this->browser, $owner->id->toString());
        self::assertCount(2, $this->browser->request('GET', $path)->filter('[data-testid="import-add-list-row"]'));
        // Without its token a change is refused before anything is read
        $this->browser->request('POST', $path, ['action' => 'leave_out', 'row' => $rowIds[0]]);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($this->browser, $other->id->toString());
        $token = (string) $this->browser->request('GET', '/en/import/' . $otherImportId . '/add/list')->filter('[data-testid="import-add-list-row"] input[name="_token"]')->first()->attr('value');
        $this->browser->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        foreach ([['action' => 'leave_out', 'row' => $rowIds[0]], ['action' => 'put_back', 'row' => $rowIds[0]], ['action' => 'leave_out_puzzle', 'puzzle' => Uuid::uuid7()->toString()], ['action' => 'leave_out_sheet', 'sheet' => Uuid::uuid7()->toString(), 'version' => 1]] as $fields) {
            $this->browser->request('POST', $path, [...$fields, '_token' => $token]);
            self::assertResponseStatusCodeSame(404, $fields['action']);
        }

        // Their own import with the owner's row: nothing of the owner's is touched
        $this->browser->request('POST', '/en/import/' . $otherImportId . '/add/list', ['_token' => $token, 'action' => 'leave_out', 'row' => $rowIds[0]]);
        self::assertContains($this->browser->getResponse()->getStatusCode(), [303, 404]);
        self::assertSame(['waiting', 'waiting'], $this->database->fetchFirstColumn('SELECT state FROM data_import_row WHERE import_id = :id ORDER BY row_number', ['id' => $importId]));

        // Somebody else altogether
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheReviewAndTheListCostTheSameForThreeRowsAndForSixty(): void
    {
        $budgets = [];

        foreach (['few' => 3, 'many' => 60] as $size => $rows) {
            [$player, $importId] = $this->importOfRows('listbudget' . $size, $rows, people: true);
            TestingLogin::asPlayer($this->browser, $player->id->toString());
            $budgets[$size] = [
                'review' => $this->statements('/en/import/' . $importId . '/add'),
                'list' => $this->statements('/en/import/' . $importId . '/add/list'),
                'search' => $this->statements('/en/import/' . $importId . '/add/list?q=puzzle&makes=results'),
                'home' => $this->statements('/en/import'),
            ];
        }

        self::assertSame($budgets['few'], $budgets['many'], 'the number of statements must not grow with the rows');

        // On top of what every signed-in page loads (Imports home reads two of its own): the list - the import, what
        // an "Add" would do with its sheets, a page of rows (3); the review of a file that names people - the import,
        // the summary, the rows it shows, the equal rows, the people's counts and who the named ones are (6)
        $signedIn = $budgets['few']['home'] - 2;
        self::assertSame(['review' => $signedIn + 6, 'list' => $signedIn + 3, 'search' => $signedIn + 3], array_diff_key($budgets['few'], ['home' => 1]));
    }

    /**
     * An import of a new player whose rows are all ready: results of one made-up puzzle - with $people every row a
     * pair result with a guest.
     *
     * @return array{\SpeedPuzzling\Web\Entity\Player, string, list<string>}
     */
    private function importOfRows(string $name, int $rows, bool $people = false): array
    {
        $player = $this->seedPairingPlayer($name);
        $marker = 'Zq' . ucfirst($name);
        $this->seedCataloguePuzzle($marker . ' Puzzle', 500, $this->seedBrand($marker . 'brand'));

        [$import, $sheet] = $this->importOf($player);
        $rowIds = [];

        for ($row = 0; $row < $rows; $row++) {
            $rowIds[] = $this->resultRow(
                $import,
                $sheet,
                $marker . ' Puzzle',
                500,
                $marker . 'brand',
                (new DateTimeImmutable('2023-01-01'))->modify('+' . $row . ' days')->format('Y-m-d'),
                3000 + $row,
                $people ? ['type' => 'duo', 'people' => ['Anna']] : [],
            );
        }

        $this->pair($import);
        $this->sheetsAreRead($import->id->toString());

        return [$player, $import->id->toString(), $rowIds];
    }

    /**
     * One more sheet of the import, as the file's reading leaves it.
     */
    private function sheet(DataImport $import, int $position, string $title): DataImportSheet
    {
        $entityManager = $this->pairingEntityManager();
        $managed = $entityManager->find(DataImport::class, $import->id->toString());
        self::assertNotNull($managed);
        $sheet = new DataImportSheet(Uuid::uuid7(), $managed, $position, $title);
        $entityManager->persist($sheet);
        $entityManager->flush();
        $this->database->executeStatement('UPDATE data_import_sheet SET mapping_version = 1, parsed_version = 1 WHERE id = :id', ['id' => $sheet->id->toString()]);

        return $sheet;
    }

    /**
     * The import's puzzles are built again (as the queue does after every change of its rows) and each is matched
     * with the catalogue puzzle of its name - the file names no brand, so the rules alone would only suggest it.
     *
     * @param array<string, string> $puzzles name => catalogue puzzle id
     */
    private function matched(string $importId, array $puzzles): void
    {
        $queue = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $queue->reset();
        $this->pair($importId);

        foreach ($puzzles as $name => $puzzleId) {
            $this->database->executeStatement(
                "UPDATE data_import_puzzle SET choice = 'catalogue', puzzle_id = :puzzleId WHERE import_id = :importId AND name = :name",
                ['puzzleId' => $puzzleId, 'importId' => $importId, 'name' => $name],
            );
        }
    }

    private function rowIdOn(string $importId, string $sheetTitle, int $rowNumber): string
    {
        $id = $this->database->fetchOne(
            'SELECT r.id FROM data_import_row r INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id WHERE r.import_id = :id AND sheet.title = :title AND r.row_number = :row',
            ['id' => $importId, 'title' => $sheetTitle, 'row' => $rowNumber],
        );
        self::assertIsString($id);

        return $id;
    }

    /**
     * What "Check and add" says an "Add" would take and make now: rows, results.
     *
     * @return array{null|string, null|string}
     */
    private function reviewCounts(string $importId): array
    {
        $counts = $this->browser->request('GET', '/en/import/' . $importId . '/add')->filter('[data-testid="import-add-counts"]');
        self::assertResponseIsSuccessful();

        return [$counts->attr('data-rows'), $counts->attr('data-results')];
    }

    /**
     * The scenario's sheets stand for ones that are mapped and read.
     */
    private function sheetsAreRead(string $importId): void
    {
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $importId]);
    }

    /**
     * The statements of a page's second request.
     */
    private function statements(string $path): int
    {
        $this->browser->request('GET', $path);
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return $this->queryCount($this->browser);
    }

    /**
     * @return list<string>
     */
    private static function rowIds(Crawler $page): array
    {
        return $page->filter('[data-testid="import-add-list-row"]')->each(static fn (Crawler $row): string => (string) $row->attr('data-row-id'));
    }

    private static function row(Crawler $page, string $rowId): Crawler
    {
        return $page->filter('[data-testid="import-add-list-row"][data-row-id="' . $rowId . '"]');
    }

    private static function note(Crawler $page, string $rowId): string
    {
        return self::spaced(self::row($page, $rowId)->filter('[data-testid="import-add-list-note"]')->text());
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
