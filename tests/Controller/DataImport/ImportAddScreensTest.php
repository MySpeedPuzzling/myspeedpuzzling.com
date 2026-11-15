<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\ChangeImportAddingPaused;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
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
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/adding-screens.md - the player's "Add": the ready card, the review before adding, the
 * button, the live progress and what came of it, the lists, and Undo. The screens are driven as a browser drives
 * them; the worker's turns are taken by hand with the real handlers (ImportAddingScenario), so what a page said
 * before "Add" is compared with what the engine then did. Every puzzle and every row here is made up.
 */
final class ImportAddScreensTest extends WebTestCase
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

    public function testTheReadyCardLeadsToTheReviewAndSaysWhatIsNotReadyAndWhereItIsSettled(): void
    {
        $player = $this->seedPairingPlayer('card');
        $brand = $this->seedBrand('Zqcard');
        $this->seedCataloguePuzzle('Zq Card Harbour', 500, $brand);
        $this->seedCataloguePuzzle('Zq Card Meadow', 1000, $brand);
        // Two puzzles of one name: the pairing can not decide - the player chooses
        $this->seedCataloguePuzzle('Zq Card Twin', 500, $this->seedBrand('Zqcardone'));
        $this->seedCataloguePuzzle('Zq Card Twin', 500, $this->seedBrand('Zqcardtwo'));

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Card Harbour', 500, 'Zqcard', '2024-03-01', 3723);
        $this->ownedRow($import, $sheet, 'Zq Card Meadow', 1000, 'Zqcard');
        $this->wishRow($import, $sheet, 'Zq Card Harbour', 500, 'Zqcard');
        // A result known to the month only, and a row whose puzzle waits for a choice
        $this->seedRow($import, $sheet, 'Zq Card Meadow', 1000, 'Zqcard', ['date' => '2024-02', 'datePrecision' => 'month', 'seconds' => 4000, 'type' => 'solo']);
        $this->resultRow($import, $sheet, 'Zq Card Twin', 500, '', '2024-03-05', 2000);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        foreach (['/en/import/' . $importId, '/en/import/' . $importId . '/puzzles'] as $page) {
            $crawler = $this->browser->request('GET', $page);
            self::assertResponseIsSuccessful();

            $card = $crawler->filter('[data-testid="import-ready"]');
            self::assertSame(['ready', '3'], [$card->attr('data-state'), $card->attr('data-rows')], $page);
            // The wish for a puzzle the player is about to log as solved is still a wish today: three things
            self::assertSame('Results: 1 · Puzzles you own: 1 · Wish list: 1', self::spaced($card->filter('[data-testid="import-ready-counts"]')->text()));
            self::assertSame('/en/import/' . $importId . '/add', $card->filter('[data-testid="import-review-and-add"]')->attr('href'));
            self::assertSame('Review and add', self::spaced($card->filter('[data-testid="import-review-and-add"]')->text()));

            // What is not ready, in plain words, with the place where it is settled
            self::assertSame('1', $card->filter('[data-testid="not-ready-undecided"]')->attr('data-rows'));
            self::assertSame('/en/import/' . $importId . '/puzzles', $card->filter('[data-testid="not-ready-undecided"] a')->attr('href'));
            self::assertStringContainsString('Results without an exact day: 1. They arrive with a later step - nothing is lost.', self::spaced($card->filter('[data-testid="not-ready-exact-day"]')->text()));
            self::assertStringNotContainsString('arrives soon', (string) $this->browser->getResponse()->getContent());
            // The card is inside the part of the page that follows the pairing by itself
            self::assertCount(1, $crawler->filter('turbo-frame[data-import-live] [data-testid="import-ready"]'));
        }

        // A puzzle kept as unlisted: its rows wait for a later step, said so
        $twin = $this->importPuzzle($importId, 'Zq Card Twin');
        $this->database->update('data_import_puzzle', ['choice' => 'unlisted'], ['id' => $twin['id']]);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertStringContainsString('Rows of unlisted puzzles: 1. Unlisted puzzles arrive with a later step - nothing is lost.', self::spaced($crawler->filter('[data-testid="not-ready-unlisted"]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="not-ready-undecided"]'));
    }

    public function testTheReviewSaysWhatWillBeAddedAndTheEngineThenAddsExactlyThat(): void
    {
        $player = $this->seedPairingPlayer('review');
        $brand = $this->seedBrand('Zqreview');
        $lighthouse = $this->seedCataloguePuzzle('Zq Review Lighthouse', 500, $brand);
        $harbour = $this->seedCataloguePuzzle('Zq Review Harbour', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Review Meadow', 300, $brand);
        $this->seedCataloguePuzzle('Zq Review Orchard', 750, $brand);
        $this->seedCataloguePuzzle('Zq Review Wish', 2000, $brand);
        // A result typed by hand before the file came: its row is "already on your profile"
        $this->typedResult($player, $harbour, '2024-01-10', 6000);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Review Lighthouse', 500, 'Zqreview', '2024-03-01', 3723, ['firstTry' => true]);
        $this->resultRow($import, $sheet, 'Zq Review Harbour', 1000, 'Zqreview', '2024-03-02', 5400, ['type' => 'duo', 'people' => ['Ben <b>Bold</b>']]);
        $this->resultRow($import, $sheet, 'Zq Review Harbour', 1000, 'Zqreview', '2024-01-10', 6000);
        $this->resultRow($import, $sheet, 'Zq Review Meadow', 300, 'Zqreview', '2024-03-03', 1500, kind: DataImportRowKind::Both);
        $this->ownedRow($import, $sheet, 'Zq Review Orchard', 750, 'Zqreview');
        $this->wishRow($import, $sheet, 'Zq Review Wish', 2000, 'Zqreview');
        // Three rows that say the same result: one result unless the player says three
        $standing = $this->resultRow($import, $sheet, 'Zq Review Lighthouse', 500, 'Zqreview', '2024-04-04', 3000);
        $second = $this->resultRow($import, $sheet, 'Zq Review Lighthouse', 500, 'Zqreview', '2024-04-04', 3000, ['occurrence' => 2]);
        $third = $this->resultRow($import, $sheet, 'Zq Review Lighthouse', 500, 'Zqreview', '2024-04-04', 3000, ['occurrence' => 3]);
        $this->database->update('data_import_row', ['same_as_row_id' => $standing], ['id' => $second]);
        $this->database->update('data_import_row', ['same_as_row_id' => $standing], ['id' => $third]);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());

        $counts = $crawler->filter('[data-testid="import-add-counts"]');
        // 9 rows are taken: 4 new results (two solo, the pair, the one of "both"), 2 puzzles for the collection, a wish;
        // 3 rows add nothing - the one typed before and the two that repeat a row
        self::assertSame(
            ['9', '4', '2', '1', '3'],
            [$counts->attr('data-rows'), $counts->attr('data-results'), $counts->attr('data-owned'), $counts->attr('data-wish-list'), $counts->attr('data-already-yours')],
        );
        self::assertSame(['Results', 'Rows ready: 9'], [self::spaced($crawler->filter('[data-testid="import-add-sheet"] span')->eq(0)->text()), self::spaced($crawler->filter('[data-testid="import-add-sheet"] span')->eq(1)->text())]);

        // The first results as they will appear: the catalogue puzzle, time, day, kind, the people as guests, first try
        $sample = $crawler->filter('[data-testid="import-add-sample"] [data-testid="add-row"]');
        self::assertCount(4, $sample);
        self::assertSame('Zq Review Lighthouse', self::spaced($sample->eq(0)->filter('[data-testid="add-row-puzzle"]')->text()));
        self::assertSame('1:02:03 · Mar 1, 2024 · solo · first try', self::spaced($sample->eq(0)->filter('[data-testid="add-row-said"]')->text()));
        self::assertSame('1:30:00 · Mar 2, 2024 · pair · with Ben <b>Bold</b> (as guests)', self::spaced($sample->eq(1)->filter('[data-testid="add-row-said"]')->text()));
        // A name's markup is text, never markup - and nothing of the file is in an address
        self::assertStringContainsString('Ben &lt;b&gt;Bold&lt;/b&gt;', (string) $this->browser->getResponse()->getContent());
        self::assertStringNotContainsString('<b>Bold</b>', (string) $this->browser->getResponse()->getContent());
        self::assertSame([], self::addressesNaming($crawler, ['Zq', 'Ben', 'Bold', 'Lighthouse']));

        // The same result on three rows: asked, one by default
        $group = $crawler->filter('[data-testid="import-add-equal-group"]');
        self::assertSame(['3', 'false'], [$group->attr('data-rows'), $group->attr('data-separate')]);
        self::assertSame('Rows: 3 - counted as one result.', self::spaced($group->filter('[data-testid="import-add-equal-state"]')->text()));
        self::assertSame('They are 3 results', self::spaced($group->filter('[data-testid="import-add-equal-switch"]')->text()));

        // Already on the profile: a count and a folded list, nothing to do
        $known = $crawler->filter('details[data-testid="import-add-known"]');
        self::assertSame('3', $known->attr('data-rows'));
        self::assertNull($known->attr('open'));
        self::assertCount(1, $known->filter('[data-testid="add-row"]'), 'The row typed before; the two that repeat a row are in their own group');

        // What an import does not do, in two short lines
        self::assertStringContainsString('Your followers are not notified.', $crawler->filter('[data-testid="import-add-what"]')->text());
        self::assertStringContainsString('Results are dated by the day you solved them', $crawler->filter('[data-testid="import-add-what"]')->text());

        // Nothing was added by looking, and nothing starts by itself
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));

        // The button: a real POST with its token and one id for this press
        $form = $crawler->filter('[data-testid="import-add-form"]');
        self::assertSame(['/en/import/' . $importId . '/add', 'post'], [$form->attr('action'), strtolower((string) $form->attr('method'))]);
        self::assertSame('Add 7 to my profile', self::spaced($form->filter('[data-testid="import-add-button"]')->text()));
        $requestId = (string) $form->filter('input[name="request_id"]')->attr('value');

        $this->browser->request('POST', '/en/import/' . $importId . '/add', ['_token' => $this->token($crawler), 'request_id' => $requestId]);
        self::assertResponseStatusCodeSame(302);
        $batchId = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_batch WHERE import_id = :id', ['id' => $importId]);
        self::assertIsString($batchId);
        self::assertResponseRedirects('/en/import/' . $importId . '/batches/' . $batchId);
        // Nothing is added in the request
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));

        // The batch's page follows the work: the live line with the numbers, safe to leave
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batchId);
        self::assertResponseIsSuccessful();
        self::assertSame('running', $crawler->filter('[data-testid="import-batch-page"]')->attr('data-state'));
        $live = $crawler->filter('[data-testid="import-adding-live"]');
        self::assertSame('data-import-adding', $live->attr('data-controller'));
        self::assertSame([$batchId, '9', '0'], [$live->attr('data-data-import-adding-batch-id-value'), $live->attr('data-data-import-adding-total-value'), $live->attr('data-data-import-adding-done-value')]);
        self::assertSame('Adding… 0 of 9', self::spaced($live->filter('[data-testid="import-adding-line"]')->text()));
        self::assertStringContainsString('You can leave this page', $live->text());
        $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batchId . '/progress');
        self::assertSame(['id' => $batchId, 'running' => true, 'paused' => false, 'total' => 9, 'done' => 0], json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        // While it runs the import's card leads to it instead of offering another "Add"
        $card = $this->browser->request('GET', '/en/import/' . $importId)->filter('[data-testid="import-ready"]');
        self::assertSame('adding', $card->attr('data-state'));
        self::assertSame('/en/import/' . $importId . '/batches/' . $batchId, $card->filter('[data-testid="import-ready-follow"]')->attr('href'));
        self::assertCount(0, $card->filter('[data-testid="import-review-and-add"]'));

        // The worker - the real one - and then: what the review said is what was done
        $this->work($batchId);
        $batch = $this->batchNow($batchId);
        self::assertSame(
            ['finished', 9, 4, 2, 1, 3, 0, 0],
            [$batch['state'], $batch['rows_requested'], $batch['results_added'], $batch['collection_items_added'], $batch['wish_list_items_added'], $batch['rows_already_yours'], $batch['rows_refused'], $batch['rows_failed']],
        );
        self::assertSame(5, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND puzzle_id = :puzzle AND seconds_to_solve = 3000', ['id' => $player->id->toString(), 'puzzle' => $lighthouse]));

        // Done: counts by outcome, the ways on, Undo
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batchId);
        self::assertSame('finished', $crawler->filter('[data-testid="import-batch-page"]')->attr('data-state'));
        $outcome = $crawler->filter('[data-testid="import-batch-outcome"]');
        self::assertSame(
            ['6', '3', '0', '4', '2', '1'],
            [$outcome->attr('data-added'), $outcome->attr('data-already-yours'), $outcome->attr('data-not-added'), $outcome->attr('data-results'), $outcome->attr('data-owned'), $outcome->attr('data-wish-list')],
        );
        self::assertSame('Results: 4 · Puzzles you own: 2 · Wish list: 1', self::spaced($outcome->filter('[data-testid="import-batch-made"]')->text()));
        self::assertSame('/en/my-profile', $crawler->filter('[data-testid="import-batch-profile"]')->attr('href'));
        self::assertSame('/en/import/' . $importId . '/added?batch=' . $batchId, $crawler->filter('[data-testid="import-batch-added-link"]')->attr('href'));
        self::assertSame('/en/import/' . $importId . '/undo?batch=' . $batchId, $crawler->filter('[data-testid="import-undo-batch"]')->attr('href'));
        self::assertSame('/en/import/' . $importId . '/undo', $crawler->filter('[data-testid="import-undo-import"]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="import-batch-reasons"]'));
        $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batchId . '/progress');
        self::assertStringContainsString('"running":false', (string) $this->browser->getResponse()->getContent());

        // Nothing is left to add: the review says so and shows no button
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertCount(1, $crawler->filter('[data-testid="import-add-nothing"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-add-form"]'));
    }

    public function testAResentAddFormAnswersTheSameBatch(): void
    {
        [$player, $importId] = $this->importWithResults('resent', 3);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        $fields = ['_token' => $this->token($crawler), 'request_id' => (string) $crawler->filter('input[name="request_id"]')->attr('value')];

        $this->browser->request('POST', '/en/import/' . $importId . '/add', $fields);
        self::assertResponseStatusCodeSame(302);
        $first = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/en/import/' . $importId . '/batches/[0-9a-f-]{36}$#', $first);

        // A double tap, a resent form, the same form in a second tab: the same batch answers, nothing is queued twice
        $this->browser->request('POST', '/en/import/' . $importId . '/add', $fields);
        self::assertResponseStatusCodeSame(302);
        self::assertSame($first, $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));
        self::assertSame(3, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state = 'queued'", ['id' => $importId]));

        // Another press while that one runs: one at a time - said calmly, and the player lands on the running one
        $this->browser->request('POST', '/en/import/' . $importId . '/add', [...$fields, 'request_id' => '0193e7a0-0000-7000-8000-000000000001']);
        self::assertResponseRedirects($first);
        self::assertStringContainsString('still running - one at a time', $this->browser->followRedirect()->text());
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));

        // A form without an id of its own starts nothing
        $this->browser->request('POST', '/en/import/' . $importId . '/add', ['_token' => $fields['_token'], 'request_id' => 'not-an-id']);
        self::assertResponseRedirects('/en/import/' . $importId . '/add');
    }

    public function testWhileAddingIsPausedThereIsNoButtonAndThePostIsRefusedCalmly(): void
    {
        [$player, $importId] = $this->importWithResults('paused', 2);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        $fields = ['_token' => $this->token($crawler), 'request_id' => (string) $crawler->filter('input[name="request_id"]')->attr('value')];

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeImportAddingPaused(true, PlayerFixture::PLAYER_ADMIN, 'Looking at something'));

        // The card says so calmly - and offers no "Review and add"
        $card = $this->browser->request('GET', '/en/import/' . $importId)->filter('[data-testid="import-ready"]');
        self::assertSame('paused', $card->attr('data-state'));
        self::assertStringContainsString('Adding is paused for a moment by our team.', self::spaced($card->filter('[data-testid="import-ready-paused"]')->text()));
        self::assertStringContainsString('nothing is lost', $card->text());
        self::assertCount(0, $card->filter('[data-testid="import-review-and-add"]'));
        self::assertCount(0, $card->filter('button'));
        // The admin's reason is the admins' - never shown to the player
        self::assertStringNotContainsString('Looking at something', (string) $this->browser->getResponse()->getContent());

        // The review can be looked at, without the button
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="import-add-paused"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-add-form"]'));

        // A form that was open before the pause: refused with the calm line, nothing queued
        $this->browser->request('POST', '/en/import/' . $importId . '/add', $fields);
        self::assertResponseRedirects('/en/import/' . $importId . '/add');
        $page = $this->browser->followRedirect();
        self::assertStringContainsString('Adding is paused for a moment by our team. Nothing was started and nothing is lost', self::spaced($page->text()));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state <> 'waiting'", ['id' => $importId]));

        // Switched on again: the button is back
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeImportAddingPaused(false, PlayerFixture::PLAYER_ADMIN, null));
        self::assertCount(1, $this->browser->request('GET', '/en/import/' . $importId . '/add')->filter('[data-testid="import-add-form"]'));
    }

    public function testAnUnusualTimeIsAskedAboutBeforeAddingConfirmedItIsAddedLeftOutItIsNot(): void
    {
        $player = $this->seedPairingPlayer('times');
        $brand = $this->seedBrand('Zqtimes');
        $puzzle = $this->seedCataloguePuzzle('Zq Times Puzzle', 1000, $brand);
        // The player's own times are the evidence: around three hours for this puzzle
        $this->typedResult($player, $puzzle, '2023-01-01', 10800);
        $this->typedResult($player, $puzzle, '2023-02-01', 10500);
        $this->typedResult($player, $puzzle, '2023-03-01', 10200);

        // A file read, mapped and parsed by the real handlers: "Leave out" is the pairing screens' own answer, which
        // reads the row again with its sheet's mapping
        // (the usual times stand first: a row is judged against the results that are there when its turn comes)
        $importId = $this->importedWorkbook($player, [
            ['Zq Times Puzzle', 1000, '2023-03-20', 10000],
            ['Zq Times Puzzle', 1000, '2023-03-21', 10100],
            ['Zq Times Puzzle', 1000, '2023-04-01', 1700],
            ['Zq Times Puzzle', 1000, '2023-04-02', 1600],
        ]);
        $this->pair($importId);
        // The file names no brand, so the rules only say "probably": the player's choice is made here
        $this->database->update('data_import_puzzle', ['choice' => 'catalogue', 'puzzle_id' => $puzzle], ['import_id' => $importId]);
        [$usual, $right, $leftOut] = [$this->rowId($importId, 2), $this->rowId($importId, 4), $this->rowId($importId, 5)];
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // The review loads the times into a frame of its own - with a plain link for a browser without a script
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        $frame = $crawler->filter('turbo-frame#import-add-times');
        self::assertSame(['/en/import/' . $importId . '/add/times', 'lazy'], [$frame->attr('src'), $frame->attr('loading')]);
        self::assertSame('/en/import/' . $importId . '/add/times', $frame->filter('a')->attr('href'));
        $token = $this->token($crawler);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add/times');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#import-add-times'));
        $unusual = $crawler->filter('[data-testid="import-add-unusual"]');
        self::assertSame([$right, $leftOut], $unusual->each(static fn (Crawler $row): null|string => $row->attr('data-row-id')));
        self::assertSame('fast', $unusual->eq(0)->attr('data-direction'));
        self::assertStringContainsString('Much faster than your other results suggest - we expected about', self::spaced($unusual->eq(0)->filter('[data-testid="import-add-unusual-why"]')->text()));
        self::assertStringNotContainsString('uspicious', (string) $this->browser->getResponse()->getContent());
        // Its forms leave the frame, name their own address and carry the token
        $form = $unusual->eq(0)->filter('form');
        self::assertSame(['/en/import/' . $importId . '/add/confirm', '_top'], [$form->attr('action'), $form->attr('data-turbo-frame')]);
        self::assertSame('They are all right (2)', self::spaced($crawler->filter('[data-testid="import-add-unusual-all-right"]')->text()));

        // "It is right" for one, "Leave out" for the other
        $this->browser->request('POST', '/en/import/' . $importId . '/add/confirm', ['_token' => $token, 'action' => 'right', 'rows' => [$right]]);
        self::assertResponseRedirects('/en/import/' . $importId . '/add#unusual-times');
        self::assertSame('{"addAnyway": ["unusual_time"]}', $this->database->fetchOne('SELECT answers FROM data_import_row WHERE id = :id', ['id' => $right]));
        $this->browser->request('POST', '/en/import/' . $importId . '/add/confirm', ['_token' => $token, 'action' => 'leave_out', 'rows' => [$leftOut]]);
        self::assertResponseRedirects('/en/import/' . $importId . '/add#unusual-times');
        self::assertStringContainsString('Left out.', $this->browser->followRedirect()->text());
        self::assertSame('left_out', $this->rowNow($leftOut)['state']);

        // Asked once: neither is listed any more, and the review counts what is left
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add/times');
        self::assertCount(0, $crawler->filter('[data-testid="import-add-unusual"]'));
        self::assertCount(1, $crawler->filter('[data-testid="import-add-times-none"]'));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertSame('3', $crawler->filter('[data-testid="import-add-counts"]')->attr('data-results'));

        $this->browser->request('POST', '/en/import/' . $importId . '/add', ['_token' => $token, 'request_id' => (string) $crawler->filter('input[name="request_id"]')->attr('value')]);
        $batchId = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_batch WHERE import_id = :id', ['id' => $importId]);
        self::assertIsString($batchId);
        $this->work($batchId);

        self::assertSame(['added', 'added', 'left_out'], [$this->rowNow($right)['state'], $this->rowNow($usual)['state'], $this->rowNow($leftOut)['state']]);
        self::assertSame(3, $this->batchNow($batchId)['results_added']);
        self::assertSame(6, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
        // "It is right" is stored the way the add form's answer is
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM suspicious_time_confirmation WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testEqualRowsAreOneResultUntilThePlayerSaysThree(): void
    {
        $player = $this->seedPairingPlayer('equal');
        $this->seedCataloguePuzzle('Zq Equal Screens', 500, $this->seedBrand('Zqequalscreens'));

        [$import, $sheet] = $this->importOf($player);
        $standing = $this->resultRow($import, $sheet, 'Zq Equal Screens', 500, 'Zqequalscreens', '2024-01-01', 1800);
        $second = $this->resultRow($import, $sheet, 'Zq Equal Screens', 500, 'Zqequalscreens', '2024-01-01', 1800, ['occurrence' => 2]);
        $third = $this->resultRow($import, $sheet, 'Zq Equal Screens', 500, 'Zqequalscreens', '2024-01-01', 1800, ['occurrence' => 3]);
        $this->database->update('data_import_row', ['same_as_row_id' => $standing], ['id' => $second]);
        $this->database->update('data_import_row', ['same_as_row_id' => $standing], ['id' => $third]);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertSame(['3', '1', '2'], [$crawler->filter('[data-testid="import-add-counts"]')->attr('data-rows'), $crawler->filter('[data-testid="import-add-counts"]')->attr('data-results'), $crawler->filter('[data-testid="import-add-counts"]')->attr('data-already-yours')]);
        $group = $crawler->filter('[data-testid="import-add-equal-group"]');
        self::assertSame($standing, $group->filter('input[name="group"]')->attr('value'));
        $token = $this->token($crawler);

        // "They are 3 results"
        $this->browser->request('POST', '/en/import/' . $importId . '/add/confirm', ['_token' => $token, 'action' => 'separate', 'group' => $standing]);
        self::assertResponseRedirects('/en/import/' . $importId . '/add#equal-rows');
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND add_as_separate', ['id' => $importId]));
        $crawler = $this->browser->followRedirect();
        self::assertSame(['3', '3', '0'], [$crawler->filter('[data-testid="import-add-counts"]')->attr('data-rows'), $crawler->filter('[data-testid="import-add-counts"]')->attr('data-results'), $crawler->filter('[data-testid="import-add-counts"]')->attr('data-already-yours')]);
        self::assertSame('true', $crawler->filter('[data-testid="import-add-equal-group"]')->attr('data-separate'));
        self::assertSame('It is one result', self::spaced($crawler->filter('[data-testid="import-add-equal-switch"]')->text()));

        // ... back to one, and to three again: the answer is the player's to change until the rows are added
        $this->browser->request('POST', '/en/import/' . $importId . '/add/confirm', ['_token' => $token, 'action' => 'one', 'group' => $standing]);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND add_as_separate', ['id' => $importId]));
        $this->browser->request('POST', '/en/import/' . $importId . '/add/confirm', ['_token' => $token, 'action' => 'separate', 'group' => $standing]);
        // A group named by a row that is not the import's: nothing is written
        $this->browser->request('POST', '/en/import/' . $importId . '/add/confirm', ['_token' => $token, 'action' => 'one', 'group' => '0193e7a0-0000-7000-8000-000000000009']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND add_as_separate', ['id' => $importId]));

        $outcome = $this->addAndWork($importId, $player);
        self::assertSame([3, 3, 0], [$this->batchNow($outcome->id)['rows_added'], $this->batchNow($outcome->id)['results_added'], $this->batchNow($outcome->id)['rows_already_yours']]);
        self::assertSame(3, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testTheDonePageCountsByReasonAndTheRowsThatCouldNotBeAddedCanBeSettledFromTheirList(): void
    {
        $player = $this->seedPairingPlayer('reasons');
        $brand = $this->seedBrand('Zqreasons');
        $puzzle = $this->seedCataloguePuzzle('Zq Reasons Puzzle', 500, $brand);
        $other = $this->seedCataloguePuzzle('Zq Reasons Other', 500, $brand);
        // The profile has this time on another day: the row may be the same solve written differently
        $this->typedResult($player, $puzzle, '2024-01-05', 1800);

        [$import, $sheet] = $this->importOf($player);
        $fine = $this->resultRow($import, $sheet, 'Zq Reasons Other', 500, 'Zqreasons', '2024-02-01', 2400);
        $similar = $this->resultRow($import, $sheet, 'Zq Reasons Puzzle', 500, 'Zqreasons', '2024-01-06', 1800);
        $said = $this->resultRow($import, $sheet, 'Zq Reasons Other', 500, 'Zqreasons', '2024-02-02', 2500, ['alreadyOnMsp' => true]);
        $pairOfNobody = $this->resultRow($import, $sheet, 'Zq Reasons Other', 500, 'Zqreasons', '2024-02-03', 2600, ['type' => 'duo']);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $outcome = $this->addAndWork($importId, $player);
        $batchId = (string) $outcome->id;
        self::assertSame(['added', 'similar_result_exists', 'said_already_on_profile', 'people_do_not_fit_type'], [$this->rowNow($fine)['state'], $this->rowNow($similar)['failure'], $this->rowNow($said)['failure'], $this->rowNow($pairOfNobody)['failure']]);

        // Done: the counts by outcome and - per reason - how many rows, with a plain sentence each
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batchId);
        $outcomeCard = $crawler->filter('[data-testid="import-batch-outcome"]');
        self::assertSame(['1', '0', '3'], [$outcomeCard->attr('data-added'), $outcomeCard->attr('data-already-yours'), $outcomeCard->attr('data-not-added')]);
        $reasons = [];
        $crawler->filter('[data-testid="import-batch-reason"]')->each(static function (Crawler $reason) use (&$reasons): void {
            $reasons[(string) $reason->attr('data-code')] = [$reason->attr('data-rows'), self::spaced($reason->filter('span')->text())];
        });
        ksort($reasons);
        self::assertSame(
            [
                'people_do_not_fit_type' => ['1', 'The row says pair or team and names nobody - or says solo and names people. Open the row and set who you solved with.'],
                'said_already_on_profile' => ['1', 'Your file says this result is already on MySpeedPuzzling, and we did not find it. Add it anyway if it is missing.'],
                'similar_result_exists' => ['1', 'Your profile already has a result of this puzzle that looks like this row - the same time on another day, or the same day with another time. If it is another solve, add it anyway.'],
            ],
            $reasons,
        );
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('rror', $crawler->filter('[data-testid="import-batch-page"]')->text());
        self::assertStringNotContainsString('nvalid', $content);
        self::assertSame('/en/import/' . $importId . '/not-added?batch=' . $batchId, $crawler->filter('[data-testid="import-batch-not-added-link"]')->attr('href'));

        // The list: each row with its reason and, where it helps, the way to settle it
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/not-added?batch=' . $batchId);
        self::assertResponseIsSuccessful();
        self::assertSame('3', $crawler->filter('[data-testid="import-not-added-page"]')->attr('data-rows'));
        $rows = [];
        $crawler->filter('[data-testid="import-not-added-row"]')->each(static function (Crawler $row) use (&$rows): void {
            $rows[(string) $row->attr('data-row-id')] = $row;
        });
        self::assertSame([$pairOfNobody, $said, $similar], array_keys($rows), 'Grouped by the reason');
        self::assertSame('Add it anyway', self::spaced($rows[$similar]->filter('[data-testid="import-not-added-anyway"]')->text()));
        self::assertSame('similar_result_exists', $rows[$similar]->filter('[data-testid="import-not-added-anyway"]')->attr('value'));
        self::assertCount(0, $rows[$pairOfNobody]->filter('[data-testid="import-not-added-anyway"]'));
        self::assertSame('/en/import/' . $importId . '/rows/' . $pairOfNobody, $rows[$pairOfNobody]->filter('[data-testid="import-not-added-open"]')->attr('href'));
        self::assertSame('Try again', self::spaced($rows[$pairOfNobody]->filter('[data-testid="import-not-added-again"]')->text()));
        // Every row's button has an id of its own
        $requestIds = $crawler->filter('[data-testid="import-not-added-row"] input[name="request_id"]')->each(static fn (Crawler $input): null|string => $input->attr('value'));
        self::assertCount(3, array_unique($requestIds));
        // The import's card names them too
        self::assertSame('/en/import/' . $importId . '/not-added', $this->browser->request('GET', '/en/import/' . $importId)->filter('[data-testid="not-ready-not-added"] a')->attr('href'));

        // "Add it anyway": the answer is written and the row is added by its own "Add"
        $fields = [
            '_token' => $this->token($crawler),
            'request_id' => (string) $rows[$similar]->filter('input[name="request_id"]')->attr('value'),
            'rows' => [$similar],
            'batch' => $batchId,
            'anyway' => 'similar_result_exists',
        ];
        $this->browser->request('POST', '/en/import/' . $importId . '/add', $fields);
        self::assertResponseStatusCodeSame(302);
        $second = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_batch WHERE import_id = :id AND id <> :first', ['id' => $importId, 'first' => $batchId]);
        self::assertIsString($second);
        self::assertResponseRedirects('/en/import/' . $importId . '/batches/' . $second);
        self::assertSame(['queued', '{"addAnyway": ["similar_result_exists"]}'], [$this->rowNow($similar)['state'], $this->database->fetchOne('SELECT answers FROM data_import_row WHERE id = :id', ['id' => $similar])]);
        self::assertSame(1, $this->batchNow($second)['rows_requested']);
        // The same press again: the same batch
        $this->browser->request('POST', '/en/import/' . $importId . '/add', $fields);
        self::assertResponseRedirects('/en/import/' . $importId . '/batches/' . $second);
        $this->work($second);
        self::assertSame('added', $this->rowNow($similar)['state']);
        self::assertSame(3, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));

        // A question that may not be waved through is never written as an answer: the row is only tried again
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/not-added');
        self::assertSame('2', $crawler->filter('[data-testid="import-not-added-page"]')->attr('data-rows'));
        $this->browser->request('POST', '/en/import/' . $importId . '/add', [
            '_token' => $fields['_token'],
            'request_id' => '0193e7a0-0000-7000-8000-000000000002',
            'rows' => [$pairOfNobody],
            'anyway' => 'people_do_not_fit_type',
        ]);
        self::assertResponseStatusCodeSame(302);
        self::assertNull($this->database->fetchOne('SELECT answers FROM data_import_row WHERE id = :id', ['id' => $pairOfNobody]));
    }

    public function testEveryRefusalCodeHasItsSentenceInEveryLanguage(): void
    {
        $translator = self::getContainer()->get('translator');

        $codes = [
            ...array_map(static fn (ImportRowRefusal $refusal): string => $refusal->value, ImportRowRefusal::cases()),
            ...array_map(static fn (ImportedResultRefusal $refusal): string => $refusal->value, ImportedResultRefusal::cases()),
            // What "Add" leaves for tomorrow (AddImportRowsHandler::SKIPPED_OVER_DAILY_LIMIT)
            'over_daily_limit',
        ];

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            $sentences = [];

            foreach (array_unique($codes) as $code) {
                self::assertTrue($catalogue->defines('data_import.reason.' . $code), sprintf('No sentence for "%s" in %s.', $code, $locale));
                $sentences[] = $catalogue->get('data_import.reason.' . $code);
            }

            self::assertCount(count(array_unique($codes)), array_unique($sentences), 'Every reason has a sentence of its own in ' . $locale);
        }

        // Neutral wording for a player's own data
        foreach (array_unique($codes) as $code) {
            self::assertDoesNotMatchRegularExpression('/\b(error|invalid|suspicious)\b/i', $translator->getCatalogue('en')->get('data_import.reason.' . $code), $code);
        }
    }

    public function testUndoOfARowABatchAndTheWholeImportKeepsWhatWasChangedSinceUnlessThePlayerSaysSo(): void
    {
        $player = $this->seedPairingPlayer('undo');
        $brand = $this->seedBrand('Zqundo');
        $this->seedCataloguePuzzle('Zq Undo One', 500, $brand);
        $this->seedCataloguePuzzle('Zq Undo Two', 500, $brand);
        $this->seedCataloguePuzzle('Zq Undo Three', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $one = $this->resultRow($import, $sheet, 'Zq Undo One', 500, 'Zqundo', '2024-01-01', 1800);
        $two = $this->resultRow($import, $sheet, 'Zq Undo Two', 500, 'Zqundo', '2024-01-02', 1900, kind: DataImportRowKind::Both);
        $three = $this->resultRow($import, $sheet, 'Zq Undo Three', 500, 'Zqundo', '2024-01-03', 2000);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        $batchId = (string) $this->addAndWork($importId, $player)->id;
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // What was added: each row with what it made and "Take back"
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/added?batch=' . $batchId);
        self::assertResponseIsSuccessful();
        self::assertSame('3', $crawler->filter('[data-testid="import-added-page"]')->attr('data-rows'));
        self::assertCount(3, $crawler->filter('[data-testid="import-added-row"]'));
        $rowTwo = $crawler->filter('[data-testid="import-added-row"][data-row-id="' . $two . '"]');
        self::assertStringContainsString('In your collection', $rowTwo->text());
        self::assertSame('/en/result/' . $this->rowNow($two)['solving_time_id'], $rowTwo->filter('[data-testid="import-added-result"]')->attr('href'));
        self::assertSame('/en/import/' . $importId . '/undo?row=' . $two, $rowTwo->filter('[data-testid="import-added-take-back"]')->attr('href'));

        // --- One row ---
        // The step before: what is removed and what is kept - looking removes nothing
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undo?row=' . $one);
        self::assertResponseIsSuccessful();
        self::assertSame(['rows', '1'], [$crawler->filter('[data-testid="import-undo-confirm-page"]')->attr('data-scope'), $crawler->filter('[data-testid="import-undo-confirm-page"]')->attr('data-rows')]);
        self::assertSame('1', $crawler->filter('[data-testid="import-undo-removes"]')->attr('data-results'));
        self::assertStringContainsString('Results you changed since the import stay on your profile.', $crawler->filter('[data-testid="import-undo-keeps"]')->text());
        self::assertSame('added', $this->rowNow($one)['state']);
        // A real form, no confirm() dialog
        $form = $crawler->filter('[data-testid="import-undo-form"]');
        self::assertSame(['/en/import/' . $importId . '/undo', 'post'], [$form->attr('action'), strtolower((string) $form->attr('method'))]);
        self::assertStringNotContainsString('data-turbo-confirm', $crawler->filter('[data-testid="import-undo-confirm-page"]')->html());
        self::assertCount(0, $form->filter('input[name="include_edited"]'));
        $token = $this->token($crawler);
        $fields = ['_token' => $token, 'request_id' => (string) $form->filter('input[name="request_id"]')->attr('value'), 'scope' => 'rows', 'row' => $one];

        $this->browser->request('POST', '/en/import/' . $importId . '/undo', $fields);
        self::assertResponseStatusCodeSame(302);
        $undoId = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_undo WHERE import_id = :id', ['id' => $importId]);
        self::assertIsString($undoId);
        self::assertResponseRedirects('/en/import/' . $importId . '/undos/' . $undoId . '?row=' . $one);
        // The same form again: the same Undo
        $this->browser->request('POST', '/en/import/' . $importId . '/undo', $fields);
        self::assertResponseRedirects('/en/import/' . $importId . '/undos/' . $undoId . '?row=' . $one);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_undo WHERE import_id = :id', ['id' => $importId]));

        // It runs on the worker: the page follows it
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $undoId);
        self::assertSame('running', $crawler->filter('[data-testid="import-undo-page"]')->attr('data-state'));
        self::assertSame('Taking back… 0 of 1', self::spaced($crawler->filter('[data-testid="import-adding-line"]')->text()));
        $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $undoId . '/progress');
        self::assertSame(['id' => $undoId, 'running' => true, 'paused' => false, 'total' => 1, 'done' => 0], json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $this->workUndo($undoId);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $undoId . '?row=' . $one);
        self::assertSame('finished', $crawler->filter('[data-testid="import-undo-page"]')->attr('data-state'));
        self::assertSame(['1', '1', '0'], [$crawler->filter('[data-testid="import-undo-outcome"]')->attr('data-rows'), $crawler->filter('[data-testid="import-undo-outcome"]')->attr('data-results'), $crawler->filter('[data-testid="import-undo-outcome"]')->attr('data-kept')]);
        self::assertStringContainsString('Rows ready again: 1.', $crawler->filter('[data-testid="import-undo-outcome"]')->text());
        // The row is ready again
        self::assertSame(['waiting', null], [$this->rowNow($one)['state'], $this->rowNow($one)['failure']]);
        self::assertSame('1', $this->browser->request('GET', '/en/import/' . $importId . '/add')->filter('[data-testid="import-add-counts"]')->attr('data-results'));

        // --- The batch, with a result the player changed since ---
        $editedResult = $this->rowNow($two)['solving_time_id'];
        $this->database->update('puzzle_solving_time', ['seconds_to_solve' => 1850], ['id' => $editedResult]);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undo?batch=' . $batchId);
        self::assertSame(['batch', '2'], [$crawler->filter('[data-testid="import-undo-confirm-page"]')->attr('data-scope'), $crawler->filter('[data-testid="import-undo-confirm-page"]')->attr('data-rows')]);
        self::assertSame(['2', '1'], [$crawler->filter('[data-testid="import-undo-removes"]')->attr('data-results'), $crawler->filter('[data-testid="import-undo-removes"]')->attr('data-owned')]);
        $this->browser->request('POST', '/en/import/' . $importId . '/undo', [
            '_token' => $token,
            'request_id' => (string) $crawler->filter('[data-testid="import-undo-form"] input[name="request_id"]')->attr('value'),
            'scope' => 'batch',
            'batch' => $batchId,
            // Never taken for a batch, whatever a form sends
            'include_edited' => '1',
        ]);
        $batchUndo = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_undo WHERE import_id = :id AND id <> :first', ['id' => $importId, 'first' => $undoId]);
        self::assertIsString($batchUndo);
        self::assertResponseRedirects('/en/import/' . $importId . '/undos/' . $batchUndo);
        $this->workUndo($batchUndo);

        // The changed result stays - listed, with "Remove it too"
        self::assertSame(['added', 'waiting'], [$this->rowNow($two)['state'], $this->rowNow($three)['state']]);
        self::assertSame([$editedResult], array_column($this->resultsOf($player), 'id'));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $batchUndo);
        self::assertSame(['1', '1', '1'], [$crawler->filter('[data-testid="import-undo-outcome"]')->attr('data-rows'), $crawler->filter('[data-testid="import-undo-outcome"]')->attr('data-results'), $crawler->filter('[data-testid="import-undo-outcome"]')->attr('data-kept')]);
        $kept = $crawler->filter('[data-testid="import-undo-kept-row"]');
        self::assertSame([$two], $kept->each(static fn (Crawler $row): null|string => $row->attr('data-row-id')));
        self::assertSame('Remove it too', self::spaced($kept->filter('[data-testid="import-undo-remove-too"]')->text()));
        self::assertSame('1', $kept->filter('input[name="include_edited"]')->attr('value'));
        // The list of what was added says why it is still there
        self::assertCount(1, $this->browser->request('GET', '/en/import/' . $importId . '/added')->filter('[data-testid="import-added-kept"]'));

        // --- Everything the import added: the changed result still stays ---
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undo');
        self::assertSame(['import', '1'], [$crawler->filter('[data-testid="import-undo-confirm-page"]')->attr('data-scope'), $crawler->filter('[data-testid="import-undo-confirm-page"]')->attr('data-rows')]);
        $this->browser->request('POST', '/en/import/' . $importId . '/undo', ['_token' => $token, 'request_id' => (string) $crawler->filter('[data-testid="import-undo-form"] input[name="request_id"]')->attr('value'), 'scope' => 'import']);
        self::assertResponseStatusCodeSame(302);
        $importUndo = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_undo WHERE import_id = :id AND scope = :scope', ['id' => $importId, 'scope' => 'import']);
        self::assertIsString($importUndo);
        $this->workUndo($importUndo);
        self::assertSame([$editedResult], array_column($this->resultsOf($player), 'id'));

        // --- "Remove it too": the player's own word about this one row ---
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $importUndo);
        $removeToo = $crawler->filter('[data-testid="import-undo-kept-row"] form');
        $this->browser->request('POST', '/en/import/' . $importId . '/undo', [
            '_token' => $token,
            'request_id' => (string) $removeToo->filter('input[name="request_id"]')->attr('value'),
            'scope' => 'rows',
            'row' => $two,
            'include_edited' => '1',
        ]);
        self::assertResponseStatusCodeSame(302);
        $last = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_undo WHERE import_id = :id AND include_edited', ['id' => $importId]);
        self::assertIsString($last);
        $this->workUndo($last);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame('waiting', $this->rowNow($two)['state']);

        // Nothing is left to take back: said so, without a form - and a POST starts nothing
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undo');
        self::assertCount(1, $crawler->filter('[data-testid="import-undo-nothing"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-undo-form"]'));
        $this->browser->request('POST', '/en/import/' . $importId . '/undo', ['_token' => $token, 'request_id' => '0193e7a0-0000-7000-8000-000000000003', 'scope' => 'import']);
        self::assertResponseRedirects('/en/import/' . $importId . '/added');
        self::assertStringContainsString('There is nothing to take back here.', $this->browser->followRedirect()->text());
    }

    public function testAfterTakingBackARowItsPuzzlesPairingCanBeForgotten(): void
    {
        $player = $this->seedPairingPlayer('forget');
        $catalogue = $this->seedCataloguePuzzle('Zq Forget Puzzle', 500, $this->seedBrand('Zqforget'));

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Forget Puzzle', 500, 'Zqforget', '2024-01-01', 1800);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        $this->addAndWork($importId, $player);
        $undo = $this->undo($importId, $player, DataImportUndoScope::Rows, rowIds: [$row]);
        $importPuzzle = $this->importPuzzle($importId, 'Zq Forget Puzzle');
        self::assertSame(['catalogue', $catalogue], [$importPuzzle['choice'], $importPuzzle['puzzle_id']]);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // Undo keeps the decision: the page of a single row's take-back offers to forget it
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $undo->id . '?row=' . $row);
        $forget = $crawler->filter('[data-testid="import-undo-wrong-puzzle"] form');
        self::assertSame('/en/import/' . $importId . '/rows/' . $row . '/forget-pairing', $forget->attr('action'));
        self::assertSame('Forget the pairing and choose again', self::spaced($forget->filter('[data-testid="import-forget-pairing"]')->text()));

        $this->browser->request('POST', '/en/import/' . $importId . '/rows/' . $row . '/forget-pairing', ['_token' => $this->token($crawler)]);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzle/' . $importPuzzle['id']);
        self::assertSame(['undecided', null], [$this->importPuzzle($importId, 'Zq Forget Puzzle')['choice'], $this->importPuzzle($importId, 'Zq Forget Puzzle')['puzzle_id']]);
        // ... also for the next file: remembered as "not this puzzle"
        self::assertSame('not_this_puzzle', $this->database->fetchOne('SELECT choice FROM import_puzzle_decision WHERE player_id = :id', ['id' => $player->id->toString()]));
        // The row waits for its puzzle now - it is not ready any more
        self::assertSame('1', $this->browser->request('GET', '/en/import/' . $importId)->filter('[data-testid="not-ready-undecided"]')->attr('data-rows'));

        // Without the row named (a batch's or an import's take-back) the page leads to the puzzles instead
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $undo->id);
        self::assertCount(0, $crawler->filter('[data-testid="import-forget-pairing"]'));
        self::assertSame('/en/import/' . $importId . '/puzzles', $crawler->filter('[data-testid="import-undo-wrong-puzzle"] a')->attr('href'));
    }

    public function testEveryRouteIsNotFoundForAnotherPlayerAndEveryPostNeedsItsToken(): void
    {
        [$owner, $importId, $rowIds] = $this->importWithResults('owner', 2);
        $batchId = (string) $this->addAndWork($importId, $owner)->id;
        $undoId = (string) $this->undo($importId, $owner, DataImportUndoScope::Rows, rowIds: [$rowIds[0]])->id;
        [$other, $otherImportId, $otherRowIds] = $this->importWithResults('other', 1);
        $otherBatchId = (string) $this->addAndWork($otherImportId, $other)->id;
        $otherUndoId = (string) $this->undo($otherImportId, $other, DataImportUndoScope::Import)->id;

        $gets = [
            '/add', '/add/times', '/batches/' . $batchId, '/batches/' . $batchId . '/progress', '/added', '/added?batch=' . $batchId,
            '/not-added', '/undo', '/undo?batch=' . $batchId, '/undo?row=' . $rowIds[1], '/undos/' . $undoId, '/undos/' . $undoId . '/progress',
        ];
        $posts = [
            '/add' => ['request_id' => '0193e7a0-0000-7000-8000-00000000000a'],
            '/add/confirm' => ['action' => 'right', 'rows' => [$rowIds[0]]],
            '/undo' => ['request_id' => '0193e7a0-0000-7000-8000-00000000000b', 'scope' => 'import'],
            '/rows/' . $rowIds[1] . '/forget-pairing' => [],
        ];

        // The owner reaches every one of them
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        foreach ($gets as $path) {
            $this->browser->request('GET', '/en/import/' . $importId . $path);
            self::assertResponseIsSuccessful($path);
        }

        // A POST without its token is refused before anything is read
        foreach ($posts as $path => $fields) {
            $this->browser->request('POST', '/en/import/' . $importId . $path, $fields);
            self::assertResponseStatusCodeSame(403, $path);
        }

        // Somebody else: every page a 404 - also with the ids of their own batch, undo or row mixed in
        TestingLogin::asPlayer($this->browser, $other->id->toString());
        $token = $this->token($this->browser->request('GET', '/en/import/' . $otherImportId . '/add'));

        foreach ($gets as $path) {
            $this->browser->request('GET', '/en/import/' . $importId . $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        foreach (['/batches/' . $batchId, '/batches/' . $batchId . '/progress', '/added?batch=' . $batchId, '/undo?batch=' . $batchId, '/undo?row=' . $rowIds[1], '/undos/' . $undoId, '/undos/' . $undoId . '/progress'] as $path) {
            $this->browser->request('GET', '/en/import/' . $otherImportId . $path);
            self::assertContains($this->browser->getResponse()->getStatusCode(), $path === '/added?batch=' . $batchId ? [200] : [404], $path);
        }

        // (a foreign batch's id on one's own list shows none of its rows)
        self::assertSame('0', $this->browser->request('GET', '/en/import/' . $otherImportId . '/added?batch=' . $batchId)->filter('[data-testid="import-added-page"]')->attr('data-rows'));

        foreach ($posts as $path => $fields) {
            $this->browser->request('POST', '/en/import/' . $importId . $path, [...$fields, '_token' => $token]);
            self::assertResponseStatusCodeSame(404, $path);
        }

        // ... and their own import with the owner's row or batch: nothing of the owner's is touched
        $this->browser->request('POST', '/en/import/' . $otherImportId . '/undo', ['_token' => $token, 'request_id' => '0193e7a0-0000-7000-8000-00000000000c', 'scope' => 'batch', 'batch' => $batchId]);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/import/' . $otherImportId . '/undo', ['_token' => $token, 'request_id' => '0193e7a0-0000-7000-8000-00000000000d', 'scope' => 'rows', 'row' => $rowIds[1]]);
        self::assertResponseRedirects('/en/import/' . $otherImportId . '/added');
        $this->browser->request('POST', '/en/import/' . $otherImportId . '/add', ['_token' => $token, 'request_id' => '0193e7a0-0000-7000-8000-00000000000e', 'rows' => [$rowIds[1]]]);
        self::assertResponseRedirects('/en/import/' . $otherImportId . '/not-added');
        self::assertSame('added', $this->rowNow($rowIds[1])['state']);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $owner->id->toString()]));
        self::assertSame([$otherBatchId, $otherUndoId, 1], [$otherBatchId, $otherUndoId, count($otherRowIds)]);

        // Nobody signed in: the sign-in page
        $this->browser->restart();
        $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertResponseStatusCodeSame(302);
    }

    public function testTheScreensCostTheSameForThreeRowsAndForSixty(): void
    {
        $budgets = [];

        foreach (['few' => 3, 'many' => 60] as $size => $rows) {
            [$player, $importId] = $this->importWithResults('budget' . $size, $rows);
            TestingLogin::asPlayer($this->browser, $player->id->toString());

            $budgets[$size]['review'] = $this->statements('/en/import/' . $importId . '/add');
            $budgets[$size]['card'] = $this->statements('/en/import/' . $importId);
            $batchId = (string) $this->addAndWork($importId, $player)->id;
            self::assertSame($rows, $this->batchNow($batchId)['results_added']);

            $budgets[$size]['done'] = $this->statements('/en/import/' . $importId . '/batches/' . $batchId);
            $budgets[$size]['progress'] = $this->statements('/en/import/' . $importId . '/batches/' . $batchId . '/progress');
            $budgets[$size]['added'] = $this->statements('/en/import/' . $importId . '/added');
            $budgets[$size]['not-added'] = $this->statements('/en/import/' . $importId . '/not-added');
            $budgets[$size]['undo-step'] = $this->statements('/en/import/' . $importId . '/undo?batch=' . $batchId);
            $undoId = (string) $this->undo($importId, $player, DataImportUndoScope::Batch, batchId: $batchId)->id;
            $budgets[$size]['undone'] = $this->statements('/en/import/' . $importId . '/undos/' . $undoId);
            $budgets[$size]['home'] = $this->statements('/en/import');
        }

        self::assertSame($budgets['few'], $budgets['many'], 'the number of statements must not grow with the rows');

        // The larger import's list is a page of fifty of its sixty rows
        [$player, $importId] = $this->importWithResults('budgetpage', 60);
        $this->addAndWork($importId, $player);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/added');
        self::assertCount(50, $crawler->filter('[data-testid="import-added-row"]'));
        self::assertSame('/en/import/' . $importId . '/added?page=2', $crawler->filter('[data-testid="import-pages-next"]')->attr('href'));
        self::assertCount(10, $this->browser->request('GET', '/en/import/' . $importId . '/added?page=2')->filter('[data-testid="import-added-row"]'));
        // A page past the end is the last one
        self::assertCount(10, $this->browser->request('GET', '/en/import/' . $importId . '/added?page=99')->filter('[data-testid="import-added-row"]'));

        // What the screens themselves read, on top of what every signed-in page loads: the review - the import, the
        // summary, the sheets, the sample, the equal rows, the rows already yours (6); the done page - the import and
        // the batch (2; +1 with rows that could not be added)
        // the batch (2; +1 with rows that could not be added); a list - the import, its counts, a page of rows (3);
        // the step before an Undo - the import, the batch, what would be taken back (3); an Undo - the import and
        // the Undo (2; +1 with results it kept); Imports home - the imports and what each added (2)
        $signedIn = $budgets['few']['home'] - 2;
        self::assertSame(
            ['review' => $signedIn + 6, 'done' => $signedIn + 2, 'added' => $signedIn + 3, 'not-added' => $signedIn + 3, 'undo-step' => $signedIn + 3, 'undone' => $signedIn + 2],
            array_intersect_key($budgets['few'], ['review' => 1, 'done' => 1, 'added' => 1, 'not-added' => 1, 'undo-step' => 1, 'undone' => 1]),
        );
        // The progress the live line asks for: one statement on top of the session's own
        self::assertLessThan($budgets['few']['done'], $budgets['few']['progress']);
    }

    public function testImportsHomeSaysWhatAnImportAddedAndAnImportThatAddedIsKeptNotDiscarded(): void
    {
        [$player, $importId] = $this->importWithResults('home', 2);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // Before anything was added: the import can be discarded, and home says nothing about adding
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertCount(1, $crawler->filter('button[data-testid="discard-import"]'));
        $token = $this->token($crawler);
        self::assertCount(0, $this->browser->request('GET', '/en/import')->filter('[data-testid="data-import-added"]'));

        // While it adds: said on home
        $outcome = $this->add($importId, $player);
        $home = $this->browser->request('GET', '/en/import');
        self::assertCount(1, $home->filter('[data-testid="data-import-adding"]'));
        $this->work($outcome->id);

        $home = $this->browser->request('GET', '/en/import');
        self::assertCount(0, $home->filter('[data-testid="data-import-adding"]'));
        self::assertSame('2', $home->filter('[data-testid="data-import-added"]')->attr('data-results'));
        self::assertStringContainsString('Added so far - results: 2 · puzzles you own: 0 · wish list: 0', self::spaced($home->filter('[data-testid="data-import-added"]')->text()));

        // The import's page: no Discard - it says what is kept, and how to take back what was added
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertCount(0, $crawler->filter('[data-testid="discard-import"]'));
        $keptCard = $crawler->filter('[data-testid="import-kept"]');
        self::assertStringContainsString('Discarding it would not remove anything from your profile', $keptCard->text());
        self::assertSame('/en/import/' . $importId . '/undo', $keptCard->filter('[data-testid="import-undo-import"]')->attr('href'));
        self::assertStringContainsString('Added so far - results: 2', self::spaced($crawler->filter('[data-testid="import-ready-added"]')->text()));

        // A page that was open before the "Add" still has the button: said plainly, nothing is discarded or removed
        $this->browser->request('POST', '/en/import/' . $importId . '/discard', ['_token' => $token]);
        self::assertResponseRedirects('/en/import/' . $importId);
        self::assertStringContainsString('This import stays in your list', $this->browser->followRedirect()->text());
        self::assertSame('setting_up', $this->database->fetchOne('SELECT status FROM data_import WHERE id = :id', ['id' => $importId]));
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    /**
     * An import of a new player with $rows ready results of one made-up puzzle, each on its own day.
     *
     * @return array{Player, string, list<string>} the player, the import's id, the rows' ids
     */
    private function importWithResults(string $name, int $rows): array
    {
        $player = $this->seedPairingPlayer($name);
        $marker = 'Zq' . ucfirst($name);
        $this->seedCataloguePuzzle($marker . ' Puzzle', 500, $this->seedBrand($marker . 'brand'));

        [$import, $sheet] = $this->importOf($player);
        $rowIds = [];

        for ($row = 0; $row < $rows; $row++) {
            $rowIds[] = $this->resultRow($import, $sheet, $marker . ' Puzzle', 500, $marker . 'brand', (new DateTimeImmutable('2023-01-01'))->modify('+' . $row . ' days')->format('Y-m-d'), 3000 + $row);
        }

        $this->pair($import);
        $this->sheetsAreRead($import->id->toString());

        return [$player, $import->id->toString(), $rowIds];
    }

    /**
     * An import of the player read from a made-up workbook by the real handlers: one sheet "Results" with the
     * columns Puzzle, Pieces, Date, Time and the rows given (from row 2 on).
     *
     * @param list<array{string, int, string, int}> $rows name, pieces, day, seconds
     */
    private function importedWorkbook(Player $player, array $rows): string
    {
        $text = RawXlsx::inline(...);
        $cells = [1 => ['A' => $text('Puzzle'), 'B' => $text('Pieces'), 'C' => $text('Date'), 'D' => $text('Time')]];

        foreach ($rows as $index => [$name, $pieces, $day, $seconds]) {
            $cells[$index + 2] = [
                'A' => $text($name),
                'B' => RawXlsx::n((string) $pieces),
                'C' => RawXlsx::n((string) (intdiv((int) (new DateTimeImmutable($day . ' UTC'))->format('U'), 86400) + 25569), 2),
                'D' => RawXlsx::n((string) ($seconds / 86400), 1),
            ];
        }

        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);
        (new RawXlsx())->styles(['[h]:mm:ss', 'yyyy-mm-dd'])->sheet('Results', RawXlsx::worksheet($cells))->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $queue = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $importId = Uuid::uuid7()->toString();
        $playerId = $player->id->toString();
        $bus->dispatch(new StartImport($importId, $playerId, 'Times.xlsx', strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey($playerId, $importId, DataImportFileKind::Xlsx), $content);
        $bus->dispatch(new ConfirmImportUploaded($importId, $playerId));

        for ($round = 0; $round < 20; $round++) {
            $messages = array_values(array_filter(
                array_map(static fn ($envelope): object => $envelope->getMessage(), $queue->getSent()),
                static fn (object $message): bool => $message instanceof BuildImportPuzzles === false && $message instanceof MatchImportPuzzlesFuzzy === false,
            ));

            if ($messages === []) {
                break;
            }

            $queue->reset();

            foreach ($messages as $message) {
                $handler = self::getContainer()->get(match (true) {
                    $message instanceof ReadImportFile => ReadImportFileHandler::class,
                    $message instanceof ProposeImportMappings => ProposeImportMappingsHandler::class,
                    $message instanceof ParseImportSheet => ParseImportSheetHandler::class,
                    default => self::fail('Nothing handles ' . $message::class),
                });
                self::assertIsCallable($handler);
                $entityManager = self::getContainer()->get(EntityManagerInterface::class);
                $entityManager->clear();
                $handler($message);
                $entityManager->clear();
            }
        }

        $queue->reset();

        return $importId;
    }

    private function rowId(string $importId, int $rowNumber): string
    {
        $id = $this->database->fetchOne('SELECT id FROM data_import_row WHERE import_id = :id AND row_number = :row', ['id' => $importId, 'row' => $rowNumber]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * The scenario's sheet stands for one that is mapped and read.
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
     * Every address of the page (links, forms, frames) that holds one of the words - nothing of a file may.
     *
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function addressesNaming(Crawler $page, array $words): array
    {
        $addresses = array_merge(
            $page->filter('[data-testid="import-add-page"] a')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
            $page->filter('[data-testid="import-add-page"] form')->each(static fn (Crawler $form): string => (string) $form->attr('action')),
            $page->filter('[data-testid="import-add-page"] turbo-frame[src]')->each(static fn (Crawler $frame): string => (string) $frame->attr('src')),
        );

        return array_values(array_filter($addresses, static fn (string $address): bool => array_any($words, static fn (string $word): bool => str_contains($address, $word))));
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('form[action*="/import/"] input[name="_token"]')->first()->attr('value');
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
