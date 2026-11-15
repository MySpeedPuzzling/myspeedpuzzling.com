<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * After the Add (docs/features/data-import/guided-flow.md §5.1, §5.10, §7 - slice 5 of the guided flow): "What this
 * import added" with its search, its filters and "Undo the ticked" - ONE Undo of exactly the ticked rows - and
 * Imports home, where each import is a card with one sentence of status and one button. What the pages read does
 * not grow with the rows or the imports. Every puzzle and every row here is made up.
 */
final class ImportAfterTheAddTest extends WebTestCase
{
    use QueryCountAssertions;
    use ImportAddingScenario;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testWhatThisImportAddedIsNarrowedByWhatARowMadeItsSheetAndTheSearchAllInSql(): void
    {
        [$player, $importId, $rows, $sheets] = $this->importThatAdded('list');
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $base = '/en/import/' . $importId . '/added';

        // Everything: the tabs by what a row made, the sheets with their rows, Undo a row - and what Undo does, in place
        $crawler = $this->browser->request('GET', $base);
        self::assertResponseIsSuccessful();
        $page = $crawler->filter('[data-testid="import-added-page"]');
        self::assertSame(['5', '5', '0'], [$page->attr('data-rows'), $page->attr('data-total'), $page->attr('data-selecting')]);
        self::assertSame(
            ['all' => '5', 'results' => '3', 'collection' => '2', 'wish' => '1'],
            array_column($page->filter('[data-testid="import-added-tab"]')->each(static fn (Crawler $tab): array => [(string) $tab->attr('data-makes'), $tab->attr('data-count')]), 1, 0),
        );
        self::assertSame(
            ['All sheets', 'First (3)', 'Second (2)'],
            $page->filter('[data-testid="import-added-sheet"] option')->each(static fn (Crawler $option): string => self::spaced($option->text())),
        );
        self::assertStringContainsString('Undo removes from your profile what this import added', $page->filter('[data-testid="import-explain-undo"]')->text());
        self::assertCount(5, $page->filter('[data-testid="import-added-take-back"]'));
        self::assertSame($base . '?makes=results', $page->filter('[data-testid="import-added-tab"][data-makes="results"]')->attr('href'));

        // By what a row made
        self::assertEqualsCanonicalizing([$rows['harbour'], $rows['both'], $rows['meadow']], $this->listed($base . '?makes=results'));
        self::assertEqualsCanonicalizing([$rows['both'], $rows['owned']], $this->listed($base . '?makes=collection'));
        self::assertSame([$rows['wish']], $this->listed($base . '?makes=wish'));
        // By sheet - and the tabs count what the sheet leaves
        self::assertEqualsCanonicalizing([$rows['meadow'], $rows['wish']], $this->listed($base . '?sheet=' . $sheets[1]));
        $second = $this->browser->request('GET', $base . '?sheet=' . $sheets[1]);
        self::assertSame(['2', '1'], [$second->filter('[data-testid="import-added-tab"][data-makes="all"]')->attr('data-count'), $second->filter('[data-testid="import-added-tab"][data-makes="results"]')->attr('data-count')]);
        self::assertSame($sheets[1], $second->filter('[data-testid="import-added-sheet"] option[selected]')->attr('value'));
        // By a part of the puzzle's name - the player's own text is the only thing of a file the address holds
        self::assertEqualsCanonicalizing([$rows['harbour'], $rows['both']], $this->listed($base . '?q=harbour'));
        self::assertSame([$rows['both']], $this->listed($base . '?q=harbour&makes=collection'));
        $none = $this->browser->request('GET', $base . '?q=no-such-puzzle-qxv');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $none->filter('[data-testid="import-added-row"]'));
        self::assertSame('No row with that puzzle.', self::spaced($none->filter('[data-testid="import-added-no-hit"]')->text()));
        self::assertSame('no-such-puzzle-qxv', $none->filter('[data-testid="import-added-q"]')->attr('value'));
        // A sheet of another import, a kind nobody knows, markup in the search: nothing breaks, nothing is echoed raw
        self::assertSame([], $this->listed($base . '?sheet=' . Uuid::uuid7()->toString()));
        self::assertCount(5, $this->listed($base . '?makes=everything&sheet=not-an-id'));
        $this->browser->request('GET', $base . '?q=' . rawurlencode('<b>x</b>%_'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<b>x</b>', (string) $this->browser->getResponse()->getContent());

        // What the list reads does not grow with its rows or its filters: the import, its counts, what the filters
        // offer, a page of rows (4) on top of what every signed-in page loads
        $plain = $this->statements($base);
        self::assertSame($plain, $this->statements($base . '?makes=collection&page=2'));
        self::assertSame($plain, $this->statements($base . '?q=no-such-puzzle-qxv'));
        self::assertSame($plain, $this->statements($base . '?q=harbour&sheet=' . $sheets[0] . '&select=1'));
        self::assertSame($this->statements('/en/import') - 2 + 4, $plain);

        // Somebody else's import is not found - with or without filters
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        foreach (['', '?q=harbour', '?select=1', '?sheet=' . $sheets[0]] as $query) {
            $this->browser->request('GET', $base . $query);
            self::assertResponseStatusCodeSame(404, $query);
        }
    }

    public function testUndoTheTickedIsOneUndoOfExactlyTheTickedRows(): void
    {
        [$player, $importId, $rows] = $this->importThatAdded('ticked');
        [, $otherImportId, $otherRows] = $this->importThatAdded('tickedother');
        $playerId = $player->id->toString();
        TestingLogin::asPlayer($this->browser, $playerId);
        $base = '/en/import/' . $importId;

        // "Select several": the Undo buttons become tick boxes of ONE plain form, and its button is the one primary
        $crawler = $this->browser->request('GET', $base . '/added');
        self::assertSame($base . '/added?select=1&page=1', $crawler->filter('[data-testid="import-added-select"]')->attr('href'));
        $crawler = $this->browser->request('GET', $base . '/added?select=1');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="import-added-select-form"]');
        self::assertSame([$base . '/undo', 'get'], [$form->attr('action'), strtolower((string) $form->attr('method'))]);
        self::assertCount(5, $form->filter('input[type="checkbox"][name="rows[]"]'));
        self::assertCount(0, $form->filter('input[type="checkbox"][checked]'), 'nothing is ticked for the player');
        self::assertCount(0, $crawler->filter('[data-testid="import-added-take-back"]'));
        self::assertSame(['Undo the ticked'], $crawler->filter('[data-import-flow-screen] .btn-primary')->each(static fn (Crawler $button): string => self::spaced($button->text())));
        // Without a script the button says it plainly; with one it counts - the texts come from the server
        self::assertSame(
            ['Undo the ticked', 'Undo the ticked (1)', 'Undo the ticked (2)'],
            array_slice((array) json_decode((string) $form->attr('data-data-import-ticks-labels-value'), true), 0, 3),
        );

        // No box ticked must never read as "everything": back to the list, saying so - nothing is offered to undo
        $this->browser->request('GET', $base . '/undo?ticked=1');
        self::assertResponseRedirects($base . '/added?select=1');
        self::assertStringContainsString('Tick at least one row first.', $this->browser->followRedirect()->text());

        // Two ticked - and a row of another import, which is nobody's business here: the step before says what goes
        $ticked = [$rows['harbour'], $rows['owned'], $otherRows['wish']];
        $crawler = $this->browser->request('GET', $base . '/undo?' . http_build_query(['ticked' => '1', 'rows' => $ticked]));
        self::assertResponseIsSuccessful();
        $confirm = $crawler->filter('[data-testid="import-undo-confirm-page"]');
        self::assertSame(['rows', '2', '3'], [$confirm->attr('data-scope'), $confirm->attr('data-rows'), $confirm->attr('data-several')]);
        self::assertSame('Undo these 2 rows?', self::spaced($confirm->filter('h1')->text()));
        self::assertSame(['1', '1', '0'], [
            $confirm->filter('[data-testid="import-undo-removes"]')->attr('data-results'),
            $confirm->filter('[data-testid="import-undo-removes"]')->attr('data-owned'),
            $confirm->filter('[data-testid="import-undo-removes"]')->attr('data-wish-list'),
        ]);
        self::assertSame(['Undo these 2 rows'], $confirm->filter('.btn-primary')->each(static fn (Crawler $button): string => self::spaced($button->text())));
        // Looking removes nothing
        self::assertSame(['added', 'added'], [$this->rowNow($rows['harbour'])['state'], $this->rowNow($rows['owned'])['state']]);

        $undoForm = $confirm->filter('[data-testid="import-undo-form"]');
        $fields = [
            '_token' => (string) $undoForm->filter('input[name="_token"]')->attr('value'),
            'request_id' => (string) $undoForm->filter('input[name="request_id"]')->attr('value'),
            'scope' => 'rows',
            'rows' => $undoForm->filter('input[name="rows[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')),
        ];
        self::assertEqualsCanonicalizing($ticked, $fields['rows']);

        // Without its token nothing happens
        $this->browser->request('POST', $base . '/undo', ['_token' => 'not-the-token'] + $fields);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_undo'));

        // ONE Undo for the ticked rows of this import - the engine's own scope "rows", under its own locks
        $this->browser->request('POST', $base . '/undo', $fields);
        self::assertResponseStatusCodeSame(302);
        $undo = $this->database->fetchAssociative('SELECT id, scope, rows_requested, include_edited FROM data_import_undo WHERE import_id = :id', ['id' => $importId]);
        self::assertIsArray($undo);
        self::assertSame(['rows', 2, false], [$undo['scope'], $undo['rows_requested'], $undo['include_edited']]);
        $undoId = $undo['id'];
        self::assertIsString($undoId);
        self::assertResponseRedirects($base . '/undos/' . $undoId);
        // The same form again answers the same Undo
        $this->browser->request('POST', $base . '/undo', $fields);
        self::assertResponseRedirects($base . '/undos/' . $undoId);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_undo'));

        $this->workUndo($undoId);
        // Exactly the ticked rows are off the profile and ready again; the others - and the other import - untouched
        self::assertSame(
            ['waiting', 'waiting', 'added', 'added', 'added'],
            array_map(fn (string $row): string => (string) $this->rowNow($rows[$row])['state'], ['harbour', 'owned', 'both', 'meadow', 'wish']),
        );
        self::assertSame('added', $this->rowNow($otherRows['wish'])['state']);
        self::assertSame(5, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state = 'added'", ['id' => $otherImportId]));
        // Three results were added, the ticked one is gone
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $playerId]));
        self::assertSame('3', $this->browser->request('GET', $base . '/added')->filter('[data-testid="import-added-page"]')->attr('data-rows'));

        // One box ticked is the Undo of one row, with its own step
        $one = $this->browser->request('GET', $base . '/undo?' . http_build_query(['ticked' => '1', 'rows' => [$rows['meadow']]]))->filter('[data-testid="import-undo-confirm-page"]');
        self::assertSame(['rows', '1', '0', 'Undo this row?'], [$one->attr('data-scope'), $one->attr('data-rows'), $one->attr('data-several'), self::spaced($one->filter('h1')->text())]);

        // Somebody else can neither look at the step nor undo anything of it
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', $base . '/undo?' . http_build_query(['ticked' => '1', 'rows' => [$rows['both'], $rows['meadow']]]));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['added', 'added'], [$this->rowNow($rows['both'])['state'], $this->rowNow($rows['meadow'])['state']]);
    }

    public function testImportsHomeIsOneSentenceAndOneButtonACardAndCostsTheSameForOneImportAsForThree(): void
    {
        $player = $this->seedPairingPlayer('homecards');
        $playerId = $player->id->toString();
        TestingLogin::asPlayer($this->browser, $playerId);

        // No import yet: the sentence that says what this is for, and the page's own button as its one primary
        $crawler = $this->browser->request('GET', '/en/import');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No imports yet. Have your results or your collection in a spreadsheet?', $crawler->filter('[data-testid="no-imports"]')->text());
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="import-a-spreadsheet"]')->attr('class'));
        self::assertCount(0, $crawler->filter('[data-testid="data-import"]'));
        $empty = $this->statements('/en/import');

        // One import whose puzzles wait to be matched: what is ready, what is left - and "Continue" to "Where you are"
        $brand = $this->seedBrand('Zqhomecardsbrand');
        $this->seedCataloguePuzzle('Zqhomecards Harbour', 500, $brand);
        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zqhomecards Harbour', 500, 'Zqhomecardsbrand', '2023-01-01', 3000);
        $this->resultRow($import, $sheet, 'Zqhomecards Nobody Knows Qxv', 500, '', '2023-01-02', 3100);
        $this->pair($import);
        $first = $import->id->toString();

        $card = $this->browser->request('GET', '/en/import')->filter('[data-testid="data-import"]');
        self::assertCount(1, $card);
        self::assertSame(
            ['1 row ready to add', '1 puzzle still to match'],
            $card->filter('[data-testid="data-import-status-line"]')->each(static fn (Crawler $line): string => self::spaced($line->text())),
        );
        self::assertSame(['Continue', '/en/import/' . $first], [self::spaced($card->filter('[data-testid="data-import-button"]')->text()), $card->filter('[data-testid="data-import-button"]')->attr('href')]);
        self::assertStringContainsString('btn-primary', (string) $card->filter('[data-testid="data-import-button"]')->attr('class'));
        // One button a card - and no badge, no size, no date line
        self::assertCount(1, $card->filter('a.btn, button'));
        self::assertCount(0, $card->filter('.badge'));
        // The import waits for the player: its card carries the filled button, the page's own is quiet
        self::assertStringContainsString('btn-outline-primary', (string) $this->browser->getCrawler()->filter('[data-testid="import-a-spreadsheet"]')->attr('class'));
        $one = $this->statements('/en/import');

        // A sheet that asks a question comes before the puzzles - as on "Where you are"
        $this->database->executeStatement(
            "UPDATE data_import_sheet SET kind = 'results', mapping = jsonb_build_object('questions', jsonb_build_array(jsonb_build_object('type', 'year'), jsonb_build_object('type', 'date_order'))) WHERE import_id = :id",
            ['id' => $first],
        );
        $card = $this->browser->request('GET', '/en/import')->filter('[data-testid="data-import"]');
        self::assertSame(['2 questions about your file'], $card->filter('[data-testid="data-import-status-line"]')->each(static fn (Crawler $line): string => self::spaced($line->text())));
        self::assertSame('Continue', self::spaced($card->filter('[data-testid="data-import-button"]')->text()));

        // Two more imports: every card says its own - and the page reads what it read for one
        foreach (['second', 'third'] as $name) {
            [$more, $moreSheet] = $this->importOf($player);
            $this->resultRow($more, $moreSheet, 'Zqhomecards Harbour', 500, 'Zqhomecardsbrand', '2023-02-01', $name === 'second' ? 3200 : 3300);
            $this->pair($more);
        }

        $cards = $this->browser->request('GET', '/en/import')->filter('[data-testid="data-import"]');
        self::assertCount(3, $cards);
        self::assertSame(
            ['1 row ready to add', '1 row ready to add', '2 questions about your file'],
            $cards->each(static fn (Crawler $card): string => self::spaced($card->filter('[data-testid="data-import-status"]')->text())),
        );
        self::assertSame(
            ['Check and add', 'Check and add', 'Continue'],
            $cards->each(static fn (Crawler $card): string => self::spaced($card->filter('[data-testid="data-import-button"]')->text())),
        );
        self::assertSame($one, $this->statements('/en/import'), 'Imports home reads the same for three imports as for one');
        // ... two statements of its own: the imports, and where each stands (none without an import)
        self::assertSame($empty + 1, $one);

        // Three wait for the player: the sentence instead of the button, with what it means
        $crawler = $this->browser->request('GET', '/en/import');
        self::assertCount(0, $crawler->filter('[data-testid="import-a-spreadsheet"]'));
        self::assertStringContainsString('You have 3 imports in progress. Finish or discard one to bring another.', self::spaced($crawler->filter('[data-testid="import-places-taken"]')->text()));
        self::assertStringContainsString('Up to 3 spreadsheets can be in progress at a time.', $crawler->filter('[data-testid="import-explain-places"]')->text());

        // Nobody else's imports are ever here
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        self::assertCount(0, $this->browser->request('GET', '/en/import')->filter('[data-testid="data-import"][data-import-id="' . $first . '"]'));
    }

    /**
     * An import of two sheets that added five rows: two results, a result whose puzzle went to the collection too,
     * a puzzle the player owns, a wish.
     *
     * @return array{\SpeedPuzzling\Web\Entity\Player, string, array{harbour: string, both: string, owned: string, meadow: string, wish: string}, array{string, string}}
     */
    private function importThatAdded(string $name): array
    {
        $player = $this->seedPairingPlayer('after' . $name);
        $marker = 'Zq' . $name;
        $brand = $this->seedBrand($marker . 'brand');

        foreach (['Harbour One' => 500, 'Harbour Two' => 1000, 'Tower' => 500, 'Meadow' => 300, 'Lantern' => 500] as $puzzle => $pieces) {
            $this->seedCataloguePuzzle($marker . ' ' . $puzzle, $pieces, $brand);
        }

        [$import, $first] = $this->importOf($player);
        $this->pairingDatabase()->executeStatement("UPDATE data_import_sheet SET title = 'First' WHERE id = :id", ['id' => $first->id->toString()]);
        $second = $this->secondSheet($import);

        $rows = [
            'harbour' => $this->resultRow($import, $first, $marker . ' Harbour One', 500, $marker . 'brand', '2023-01-01', 3000),
            'both' => $this->resultRow($import, $first, $marker . ' Harbour Two', 1000, $marker . 'brand', '2023-01-02', 6000, kind: DataImportRowKind::Both),
            'owned' => $this->ownedRow($import, $first, $marker . ' Tower', 500, $marker . 'brand'),
            'meadow' => $this->resultRow($import, $second, $marker . ' Meadow', 300, $marker . 'brand', '2023-02-01', 2000),
            'wish' => $this->wishRow($import, $second, $marker . ' Lantern', 500, $marker . 'brand'),
        ];
        $this->pair($import);
        $importId = $import->id->toString();
        $this->addAndWork($importId, $player);

        foreach ($rows as $row) {
            self::assertSame('added', $this->rowNow($row)['state']);
        }

        return [$player, $importId, $rows, [$first->id->toString(), $second->id->toString()]];
    }

    private function secondSheet(DataImport $import): DataImportSheet
    {
        $managed = $this->pairingEntityManager()->find(DataImport::class, $import->id->toString());
        self::assertNotNull($managed);
        $sheet = new DataImportSheet(Uuid::uuid7(), $managed, 1, 'Second');
        $this->pairingEntityManager()->persist($sheet);
        $this->pairingEntityManager()->flush();
        $this->pairingDatabase()->executeStatement('UPDATE data_import_sheet SET mapping_version = 1, parsed_version = 1 WHERE id = :id', ['id' => $sheet->id->toString()]);
        $this->pairingEntityManager()->refresh($sheet);

        return $sheet;
    }

    /**
     * The rows a page of "What this import added" lists, by id.
     *
     * @return list<string>
     */
    private function listed(string $url): array
    {
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler->filter('[data-testid="import-added-row"]')->each(static fn (Crawler $row): string => (string) $row->attr('data-row-id'));
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

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
