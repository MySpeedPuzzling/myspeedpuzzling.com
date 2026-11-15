<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProcessImportGroupAnswer;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ParseImportSheetHandler;
use SpeedPuzzling\Web\MessageHandler\ProcessImportGroupAnswerHandler;
use SpeedPuzzling\Web\MessageHandler\ProposeImportMappingsHandler;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/group-answers.md - one answer for a whole group of rows that an "Add" did not add:
 * the group cards of the done page and of the list, the answer worked out by the server (at once for a small
 * group, in turns on the worker for a large one), and the "Add" of exactly the rows that took it. The worker's
 * turns are taken by hand with the real handlers. Every puzzle and every row here is made up.
 */
final class ImportGroupAnswersTest extends WebTestCase
{
    use ImportAddingScenario;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testOneAnswerAddsEveryRowOfTheReasonOnceAndNobodyElsesRows(): void
    {
        // Sixty rows the file marks "entered on MySpeedPuzzling" - more than a page of the list - and two plain ones
        [$player, $importId, $said, $plain] = $this->importSayingItIsOnTheProfile('groupsaid', 60, 2);
        [$other, $otherImportId, $otherSaid] = $this->importSayingItIsOnTheProfile('groupother', 3, 0);
        $batchId = (string) $this->addAndWork($importId, $player)->id;
        $this->addAndWork($otherImportId, $other);
        self::assertSame(['added', 'said_already_on_profile'], [$this->rowNow($plain[0])['state'], $this->rowNow($said[0])['failure']]);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // The done page: the group with one action comes first, with its count - all 60, not the 50 of a page
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batchId);
        $card = $crawler->filter('[data-testid="import-group-said"]');
        self::assertSame('60', $card->attr('data-rows'));
        self::assertSame('They are missing - add all 60', self::spaced($card->filter('[data-testid="import-group-said-missing"]')->text()));
        self::assertSame('Leave them out (60)', self::spaced($card->filter('[data-testid="import-group-said-leave_out"]')->text()));
        // The form names the reason and the action - never the rows
        $form = $card->filter('form')->first();
        self::assertSame('/en/import/' . $importId . '/group-answers', $form->attr('action'));
        self::assertCount(0, $form->filter('input[name^="rows"]'));
        $fields = [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'request_id' => (string) $form->filter('input[name="request_id"]')->attr('value'),
            'code' => 'said_already_on_profile',
            'action' => 'add_anyway',
            'batch' => $batchId,
        ];
        // ... and the list has the same card on top, on every page
        $list = $this->browser->request('GET', '/en/import/' . $importId . '/not-added?batch=' . $batchId . '&page=2');
        self::assertCount(10, $list->filter('[data-testid="import-not-added-row"]'));
        self::assertSame('60', $list->filter('[data-testid="import-group-said"]')->attr('data-rows'));

        // One tap: a small group is answered in the request, and its rows - exactly those - are handed to an "Add"
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', $fields);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $fields['request_id']);
        $this->browser->followRedirect();
        $second = $this->database->fetchOne('SELECT id FROM data_import_batch WHERE import_id = :id AND id <> :first', ['id' => $importId, 'first' => $batchId]);
        self::assertIsString($second);
        self::assertResponseRedirects('/en/import/' . $importId . '/batches/' . $second);
        self::assertSame(60, $this->batchNow($second)['rows_requested']);

        // The same form again (a double tap): the same answer, no second "Add"
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', $fields);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $fields['request_id']);
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_group_answer WHERE import_id = :id', ['id' => $importId]));

        $this->work($second);
        self::assertSame([60, 60, 0], [$this->batchNow($second)['rows_added'], $this->batchNow($second)['results_added'], $this->batchNow($second)['rows_refused']]);
        // Every row once: 2 + 60 results, none twice
        self::assertSame(62, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(62, $this->pairingCount('SELECT COUNT(DISTINCT (finished_at, seconds_to_solve)) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state <> 'added'", ['id' => $importId]));

        // Somebody else's rows that wait with the same reason are not touched
        self::assertSame(['waiting', 'said_already_on_profile'], [$this->rowNow($otherSaid[0])['state'], $this->rowNow($otherSaid[0])['failure']]);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $other->id->toString()]));

        // ... nor can they be answered by anybody but their owner; a group of possible duplicates has no answer at all
        $this->browser->request('POST', '/en/import/' . $otherImportId . '/group-answers', [...$fields, 'request_id' => '0193e7a0-0000-7000-8000-0000000000a1', 'batch' => '']);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', [...$fields, 'request_id' => '0193e7a0-0000-7000-8000-0000000000a2', 'code' => 'similar_result_exists']);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', ['code' => 'said_already_on_profile', 'action' => 'add_anyway', 'request_id' => '0193e7a0-0000-7000-8000-0000000000a3']);
        self::assertResponseStatusCodeSame(403);
        // Nothing waits any more: said plainly
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', [...$fields, 'request_id' => '0193e7a0-0000-7000-8000-0000000000a4', 'batch' => '']);
        self::assertResponseRedirects('/en/import/' . $importId . '/not-added');
        self::assertStringContainsString('No row waits for this answer any more.', $this->browser->followRedirect()->text());
        TestingLogin::asPlayer($this->browser, $other->id->toString());
        $this->browser->request('GET', '/en/import/' . $importId . '/group-answers/' . $fields['request_id']);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/import/' . $importId . '/group-answers/' . $fields['request_id'] . '/progress');
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/import/' . $importId . '/not-added/times');
        self::assertResponseStatusCodeSame(404);
    }

    public function testALargeGroupIsGoneThroughOnTheWorkerInTurnsWithTheLiveLine(): void
    {
        [$player, $importId, $said] = $this->importSayingItIsOnTheProfile('grouplarge', 1, 0);
        // 204 more rows like it, as an "Add" left them: waiting with the reason
        $this->database->executeStatement(<<<SQL
INSERT INTO data_import_row (id, import_id, sheet_id, row_number, first_seen_version, last_seen_version, kind, parsed, parsed_version, source_key, equal_key, puzzle_key, import_puzzle_id, state, failure, add_as_separate, result_made)
SELECT
    gen_random_uuid(), base.import_id, base.sheet_id, 1000 + n, 1, 1, 'result',
    jsonb_build_object('date', to_char(DATE '2015-01-01' + n, 'YYYY-MM-DD'), 'datePrecision', 'day', 'seconds', 3000 + n, 'type', 'solo', 'alreadyOnMsp', true),
    1,
    '1:' || encode(sha256(convert_to(base.id::text || ':' || n::text, 'UTF8')), 'hex'),
    '1:' || encode(sha256(convert_to(base.id::text || ':' || n::text, 'UTF8')), 'hex'),
    base.puzzle_key, base.import_puzzle_id, 'waiting', 'said_already_on_profile', false, false
FROM data_import_row base
CROSS JOIN generate_series(1, 204) AS n
WHERE base.id = :rowId
SQL, ['rowId' => $said[0]]);
        $this->database->update('data_import_row', ['failure' => 'said_already_on_profile'], ['id' => $said[0]]);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/not-added');
        $card = $crawler->filter('[data-testid="import-group-said"]');
        self::assertSame('205', $card->attr('data-rows'));
        $form = $card->filter('form')->first();
        $answerId = (string) $form->filter('input[name="request_id"]')->attr('value');
        $queue = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $queue->reset();

        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'request_id' => $answerId,
            'code' => 'said_already_on_profile',
            'action' => 'add_anyway',
        ]);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $answerId);
        // Nothing was answered in the request: the job is on the worker's queue
        self::assertCount(1, array_filter($queue->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof ProcessImportGroupAnswer));
        self::assertSame(205, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND failure = 'said_already_on_profile'", ['id' => $importId]));

        // Its page follows it: the live line, asked from its own JSON
        $crawler = $this->browser->followRedirect();
        self::assertSame('running', $crawler->filter('[data-testid="import-group-answer-page"]')->attr('data-state'));
        self::assertSame('Going through your rows… 0 of 205', self::spaced($crawler->filter('[data-testid="import-adding-line"]')->text()));
        self::assertSame('/en/import/' . $importId . '/group-answers/' . $answerId . '/progress', $crawler->filter('[data-testid="import-adding-live"]')->attr('data-data-import-adding-url-value'));

        // The first turn: a chunk of 200
        $handler = self::getContainer()->get(ProcessImportGroupAnswerHandler::class);
        $handler(new ProcessImportGroupAnswer($answerId));
        $this->browser->request('GET', '/en/import/' . $importId . '/group-answers/' . $answerId . '/progress');
        self::assertSame(['id' => $answerId, 'running' => true, 'paused' => false, 'total' => 205, 'done' => 200], json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));

        // The second turn ends it and starts the "Add" of exactly the rows that took the answer; a turn delivered
        // again does nothing
        $handler(new ProcessImportGroupAnswer($answerId));
        $handler(new ProcessImportGroupAnswer($answerId));
        $batchId = $this->database->fetchOne('SELECT id FROM data_import_batch WHERE import_id = :id', ['id' => $importId]);
        self::assertIsString($batchId);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));
        self::assertSame(205, $this->batchNow($batchId)['rows_requested']);
        self::assertSame(['finished', 205, 205, $batchId], array_values((array) $this->database->fetchAssociative('SELECT state, rows_done, rows_answered, batch_id FROM data_import_group_answer WHERE id = :id', ['id' => $answerId])));
        $this->browser->request('GET', '/en/import/' . $importId . '/group-answers/' . $answerId);
        self::assertResponseRedirects('/en/import/' . $importId . '/batches/' . $batchId);

        $this->work($batchId, 20);
        self::assertSame(205, $this->batchNow($batchId)['results_added']);
        self::assertSame(205, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testUnusualTimesAreSummedUpBeforeOneAnswerAndARowCorrectedSinceIsNotSweptIn(): void
    {
        $player = $this->seedPairingPlayer('grouptimes');
        $puzzle = $this->seedCataloguePuzzle('Zq Group Times Puzzle', 1000, $this->seedBrand('Zqgrouptimes'));
        $playerId = $player->id->toString();
        // The player's results on MySpeedPuzzling so far: around three hours
        $this->typedResult($player, $puzzle, '2023-01-01', 10801);
        $this->typedResult($player, $puzzle, '2023-02-01', 10501);
        $this->typedResult($player, $puzzle, '2023-03-01', 10201);
        // A file whose times are mostly much faster - somebody's own history from before
        $importId = $this->importedWorkbook($player, [
            ['Zq Group Times Puzzle', 1000, '2023-03-20', 10011],
            ['Zq Group Times Puzzle', 1000, '2023-03-21', 10111],
            ['Zq Group Times Puzzle', 1000, '2023-04-01', 1711],
            ['Zq Group Times Puzzle', 1000, '2023-04-02', 1811],
            ['Zq Group Times Puzzle', 1000, '2023-04-03', 1911],
            ['Zq Group Times Puzzle', 1000, '2023-04-04', 2011],
        ]);
        $this->pair($importId);
        $this->database->update('data_import_puzzle', ['choice' => 'catalogue', 'puzzle_id' => $puzzle], ['import_id' => $importId]);
        $fast = [$this->rowId($importId, 4), $this->rowId($importId, 5), $this->rowId($importId, 6), $this->rowId($importId, 7)];
        $batchId = (string) $this->addAndWork($importId, $player)->id;
        self::assertSame(['unusual_time', 'unusual_time', 'unusual_time', 'unusual_time'], array_map(fn (string $row): null|string => $this->rowNow($row)['failure'], $fast));
        TestingLogin::asPlayer($this->browser, $playerId);

        // The done page holds the group as a frame of its own (the summary asks the time verification)
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batchId);
        $group = $crawler->filter('[data-testid="import-group-times"]');
        self::assertSame('4', $group->attr('data-rows'));
        self::assertSame('/en/import/' . $importId . '/not-added/times?batch=' . $batchId, $group->filter('turbo-frame#import-group-times')->attr('src'));
        self::assertSame('/en/import/' . $importId . '/not-added/times?batch=' . $batchId, $group->filter('a')->attr('href'));
        // The reasons with one action stand first
        self::assertSame('unusual_time', $crawler->filter('[data-testid="import-batch-reason"]')->first()->attr('data-code'));

        // The summary before the button: how many, which way, how far, examples with what was expected - and, as
        // 4 of the file's 6 times are faster than expected, the plain sentence about the whole file
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/not-added/times?batch=' . $batchId);
        self::assertResponseIsSuccessful();
        $summary = $crawler->filter('[data-testid="import-group-times-summary"]');
        self::assertSame(['4', '4', '4', '0', 'faster'], [$summary->attr('data-rows'), $summary->attr('data-looked-at'), $summary->attr('data-faster'), $summary->attr('data-slower'), $summary->attr('data-most')]);
        self::assertSame('Faster than expected: 4 · slower than expected: 0', self::spaced($summary->filter('[data-testid="import-group-times-split"]')->text()));
        self::assertMatchesRegularExpression('/^The faster ones are between [\d.]+× and [\d.]+× faster than we expected\.$/u', self::spaced($summary->filter('[data-testid="import-group-times-faster"]')->text()));
        self::assertStringContainsString('Most of the times in this file are faster than your results on MySpeedPuzzling so far - if this file is your own history, that is expected.', self::spaced($summary->filter('[data-testid="import-group-times-most"]')->text()));
        self::assertCount(4, $summary->filter('[data-testid="import-group-times-example"]'));
        self::assertStringContainsString('We expected about', $summary->filter('[data-testid="import-group-times-example"]')->first()->text());
        self::assertSame('These times are right - add all 4', self::spaced($summary->filter('[data-testid="import-group-times-right"]')->text()));
        self::assertStringNotContainsString('uspicious', (string) $this->browser->getResponse()->getContent());
        $form = $summary->filter('form')->first();
        self::assertSame('_top', $form->attr('data-turbo-frame'));
        $fields = [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'request_id' => (string) $form->filter('input[name="request_id"]')->attr('value'),
            'code' => 'unusual_time',
            'action' => 'add_anyway',
            'batch' => $batchId,
        ];

        // One of them is corrected meanwhile (the row sheet's own message): it is not that refused row any more
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerImportRows($importId, $playerId, [$fast[3]], ['time' => '0:29:11']));
        self::assertSame(['waiting', null], [$this->rowNow($fast[3])['state'], $this->rowNow($fast[3])['failure']]);

        // ... and with 3 of 6 the file is not "mostly" unusual any more: the sentence is gone
        $summary = $this->browser->request('GET', '/en/import/' . $importId . '/not-added/times?batch=' . $batchId)->filter('[data-testid="import-group-times-summary"]');
        self::assertSame(['3', ''], [$summary->attr('data-rows'), (string) $summary->attr('data-most')]);
        self::assertCount(0, $summary->filter('[data-testid="import-group-times-most"]'));

        // "These times are right - add all": the three that still wait with the question - not the corrected one
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', $fields);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $fields['request_id']);
        $second = $this->database->fetchOne('SELECT id FROM data_import_batch WHERE import_id = :id AND id <> :first', ['id' => $importId, 'first' => $batchId]);
        self::assertIsString($second);
        self::assertSame(3, $this->batchNow($second)['rows_requested']);
        self::assertSame('waiting', $this->rowNow($fast[3])['state']);
        self::assertSame([], $this->wavedThrough($fast[3]));
        $this->work($second);
        self::assertSame(['added', 'added', 'added', 'waiting'], array_map(fn (string $row): string => $this->rowNow($row)['state'], $fast));
        self::assertSame(8, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $playerId]));
        // A confirmed result is stored as the single "It is right" stores it - the scan knows the player was asked -
        // for every time the verification still finds unusual when its row is added (the first ones here: with them on
        // the profile, the last is not unusual for this player any more)
        $confirmations = $this->pairingCount('SELECT COUNT(*) FROM suspicious_time_confirmation WHERE player_id = :id', ['id' => $playerId]);
        self::assertGreaterThanOrEqual(1, $confirmations);
        self::assertLessThanOrEqual(3, $confirmations);
        // Nothing is marked by an import
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND suspicious', ['id' => $playerId]));
    }

    public function testBeforeTheAddEveryUnusualTimeOfTheFileIsSettledByOneAnswerOnTheWorker(): void
    {
        $player = $this->seedPairingPlayer('groupbefore');
        $puzzle = $this->seedCataloguePuzzle('Zq Group Before Puzzle', 1000, $this->seedBrand('Zqgroupbefore'));
        $this->typedResult($player, $puzzle, '2023-01-01', 10801);
        $this->typedResult($player, $puzzle, '2023-02-01', 10501);
        $this->typedResult($player, $puzzle, '2023-03-01', 10201);
        [$import, $sheet] = $this->importOf($player);
        $usual = $this->resultRow($import, $sheet, 'Zq Group Before Puzzle', 1000, 'Zqgroupbefore', '2023-03-20', 10011);
        $fastOne = $this->resultRow($import, $sheet, 'Zq Group Before Puzzle', 1000, 'Zqgroupbefore', '2023-04-01', 1711);
        $fastTwo = $this->resultRow($import, $sheet, 'Zq Group Before Puzzle', 1000, 'Zqgroupbefore', '2023-04-02', 1811);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $importId]);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // The review's times frame offers one answer for the whole file
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add/times');
        $group = $crawler->filter('[data-testid="import-add-times-group"]');
        self::assertSame('All unusual times in this file are right', self::spaced($group->filter('[data-testid="import-add-times-group-right"]')->text()));
        self::assertSame('Leave out all unusual times of this file', self::spaced($group->filter('[data-testid="import-add-times-group-leave_out"]')->text()));
        $form = $group->filter('form')->first();
        $answerId = (string) $form->filter('input[name="request_id"]')->attr('value');

        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'request_id' => $answerId,
            'code' => 'unusual_time',
            'action' => 'add_anyway',
            'before_add' => '1',
        ]);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $answerId);
        // Every time is shown to the time verification first: never in the request
        self::assertSame([[], []], [$this->wavedThrough($fastOne), $this->wavedThrough($fastTwo)]);

        self::getContainer()->get(ProcessImportGroupAnswerHandler::class)(new ProcessImportGroupAnswer($answerId));

        // Only the unusual ones took the word; nothing was added - the player still presses "Add"
        self::assertSame([['unusual_time'], ['unusual_time'], []], [$this->wavedThrough($fastOne), $this->wavedThrough($fastTwo), $this->wavedThrough($usual)]);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/group-answers/' . $answerId);
        self::assertResponseIsSuccessful();
        $outcome = $crawler->filter('[data-testid="import-group-answer-outcome"]');
        self::assertSame(['2', '3'], [$outcome->attr('data-answered'), $outcome->attr('data-total')]);
        self::assertStringContainsString('They are ready now - review them and add them.', $outcome->text());
        self::assertSame('/en/import/' . $importId . '/add', $crawler->filter('[data-testid="import-group-answer-review"]')->attr('href'));

        $this->addAndWork($importId, $player);
        self::assertSame(['added', 'added', 'added'], [$this->rowNow($usual)['state'], $this->rowNow($fastOne)['state'], $this->rowNow($fastTwo)['state']]);
    }

    public function testLeaveThemOutLeavesEveryRowOfTheReasonOut(): void
    {
        $player = $this->seedPairingPlayer('groupleave');
        $puzzle = $this->seedCataloguePuzzle('Zq Group Leave Puzzle', 500, $this->seedBrand('Zqgroupleave'));
        $importId = $this->importedWorkbook($player, [
            ['Zq Group Leave Puzzle', 500, '2023-04-01', 3001],
            ['Zq Group Leave Puzzle', 500, '2023-04-02', 3101],
            ['Zq Group Leave Puzzle', 500, '2023-04-03', 3201],
        ]);
        $this->pair($importId);
        $this->database->update('data_import_puzzle', ['choice' => 'catalogue', 'puzzle_id' => $puzzle], ['import_id' => $importId]);
        // As an "Add" left two of them: the file says they are on MySpeedPuzzling, and they were not found
        $this->database->executeStatement("UPDATE data_import_row SET failure = 'said_already_on_profile' WHERE import_id = :id AND row_number > 2", ['id' => $importId]);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/not-added');
        $form = $crawler->filter('[data-testid="import-group-said"] form')->last();
        $answerId = (string) $form->filter('input[name="request_id"]')->attr('value');
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'request_id' => $answerId,
            'code' => 'said_already_on_profile',
            'action' => 'leave_out',
        ]);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $answerId);
        $crawler = $this->browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(['2', '2'], [$crawler->filter('[data-testid="import-group-answer-outcome"]')->attr('data-answered'), $crawler->filter('[data-testid="import-group-answer-outcome"]')->attr('data-total')]);
        self::assertStringContainsString('They are left out.', $crawler->filter('[data-testid="import-group-answer-outcome"]')->text());
        self::assertSame(['waiting', 'left_out', 'left_out'], [$this->rowNow($this->rowId($importId, 2))['state'], $this->rowNow($this->rowId($importId, 3))['state'], $this->rowNow($this->rowId($importId, 4))['state']]);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));
    }

    public function testEveryGroupTextIsThereInEveryLanguage(): void
    {
        $translator = self::getContainer()->get('translator');
        $english = array_keys(array_filter($translator->getCatalogue('en')->all('messages'), static fn (string $key): bool => str_starts_with($key, 'data_import.group.'), ARRAY_FILTER_USE_KEY));
        self::assertGreaterThan(30, count($english));

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            foreach ($english as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key), sprintf('"%s" is missing in %s.', $key, $locale));
            }
        }
    }

    /**
     * An import of a new player with rows the file marks "entered on MySpeedPuzzling" (none is on the profile) and
     * plain rows - results of one made-up puzzle, each on its own day.
     *
     * @return array{Player, string, list<string>, list<string>} the player, the import's id, the marked rows, the plain rows
     */
    private function importSayingItIsOnTheProfile(string $name, int $marked, int $plain): array
    {
        $player = $this->seedPairingPlayer($name);
        $marker = 'Zq' . ucfirst($name);
        $this->seedCataloguePuzzle($marker . ' Puzzle', 500, $this->seedBrand($marker . 'brand'));
        [$import, $sheet] = $this->importOf($player);
        $rows = [[], []];

        for ($row = 0; $row < $marked + $plain; $row++) {
            $rows[$row < $marked ? 0 : 1][] = $this->resultRow(
                $import,
                $sheet,
                $marker . ' Puzzle',
                500,
                $marker . 'brand',
                (new DateTimeImmutable('2022-01-01'))->modify('+' . $row . ' days')->format('Y-m-d'),
                3000 + $row,
                $row < $marked ? ['alreadyOnMsp' => true] : [],
            );
        }

        $this->pair($import);
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $import->id->toString()]);

        return [$player, $import->id->toString(), $rows[0], $rows[1]];
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
     * The questions the player waved through on the row - for the row as it reads now.
     *
     * @return list<string>
     */
    private function wavedThrough(string $rowId): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $row = $entityManager->find(DataImportRow::class, $rowId);
        self::assertNotNull($row);
        $puzzleId = $this->database->fetchOne('SELECT puzzle_id FROM data_import_puzzle WHERE id = :id', ['id' => $row->importPuzzleId?->toString()]);
        $waved = array_values(array_filter(
            ['similar_result_exists', 'somebody_elses_result', 'said_already_on_profile', 'unusual_time'],
            static fn (string $code): bool => $row->isWavedThrough($code, is_string($puzzleId) ? $puzzleId : null),
        ));
        $entityManager->clear();

        return $waved;
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
