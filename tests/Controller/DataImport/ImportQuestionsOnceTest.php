<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\AnswerImportRowsGroup;
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
use SpeedPuzzling\Web\Services\DataImport\ImportSaidAlreadyCheck;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use SpeedPuzzling\Web\Value\DataImportFileKind;

/**
 * docs/features/data-import/adding.md "A question is asked once" (guided flow, slice 6): a row with two doubts - an
 * unusual time, and "the file says it is on MySpeedPuzzling" - is told both by one "Add" and answered on one screen;
 * "already on MySpeedPuzzling" is asked before the "Add", counted by the engine's own question; an answer for a
 * whole group stands for the file. And what must not move: a possible duplicate, a taken first try, a time nobody
 * puzzles in and people that do not fit are refused exactly as before, whatever stands.
 *
 * Every puzzle and every row here is made up. The worker's turns are taken by hand with the real handlers.
 */
final class ImportQuestionsOnceTest extends WebTestCase
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

    public function testARowWithTwoDoubtsIsToldBothAndAddedByOneAnswerOnOneScreen(): void
    {
        [$player, $importId, $rows] = $this->importWithBothDoubts('oncetwo');
        $batchId = (string) $this->addAndWork($importId, $player)->id;

        // One "Add" looked at both questions: the reason is the first, the row is told both
        foreach ($rows['both'] as $rowId) {
            self::assertSame(['waiting', 'unusual_time', ['unusual_time', 'said_already_on_profile']], [$this->rowNow($rowId)['state'], $this->rowNow($rowId)['failure'], $this->openQuestions($rowId)]);
        }

        self::assertSame(['unusual_time', []], [$this->rowNow($rows['fast'])['failure'], $this->openQuestions($rows['fast'])]);
        self::assertSame(['said_already_on_profile', []], [$this->rowNow($rows['said'])['failure'], $this->openQuestions($rows['said'])]);
        self::assertSame('added', $this->rowNow($rows['plain'])['state']);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // The card of the unusual times says up front that two of them wait with the other question too, and asks it
        // right there - nothing is preselected, "add them" does not go without an answer
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/not-added/times?batch=' . $batchId);
        self::assertResponseIsSuccessful();
        $also = $crawler->filter('[data-testid="import-group-times-also"]');
        self::assertSame(['2', '0'], [$also->attr('data-rows'), $also->attr('data-stands')]);
        self::assertStringContainsString('Some of these also say they are already on MySpeedPuzzling, and we did not find them: 2. Answer that too:', self::spaced($also->text()));
        self::assertSame(['add_anyway', 'leave_out'], $also->filter('input[type="radio"][name="also[said_already_on_profile]"]')->each(static fn ($radio): string => (string) $radio->attr('value')));
        self::assertCount(2, $also->filter('input[type="radio"][required]'));
        self::assertCount(0, $also->filter('input[type="radio"][checked]'));
        $form = $crawler->filter('[data-testid="import-group-times-form"]');
        $answerId = (string) $form->filter('input[name="request_id"]')->attr('value');

        // The list of the rows says both sentences on the row's card, and its one button answers both
        $card = $this->browser->request('GET', '/en/import/' . $importId . '/not-added')->filter('[data-testid="import-not-added-row"][data-row-id="' . $rows['both'][0] . '"]');
        self::assertSame('said_already_on_profile', $card->filter('[data-testid="import-not-added-also"]')->attr('data-code'));
        self::assertStringContainsString('One answer covers both.', $card->filter('[data-testid="import-not-added-also"]')->text());
        self::assertSame('said_already_on_profile', $card->filter('input[name="anyway_also[]"]')->attr('value'));

        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'request_id' => $answerId,
            'code' => 'unusual_time',
            'action' => 'add_anyway',
            'batch' => $batchId,
            'also' => ['said_already_on_profile' => 'add_anyway'],
        ]);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $answerId);

        // One answer, one "Add" of the three rows with an unusual time - the two with both doubts among them
        $answer = $this->database->fetchAssociative('SELECT state, rows_answered, rows_still_waiting, batch_id, also FROM data_import_group_answer WHERE id = :id', ['id' => $answerId]);
        self::assertIsArray($answer);
        self::assertSame(['finished', 3, 0, '{"said_already_on_profile": "add_anyway"}'], [$answer['state'], $answer['rows_answered'], $answer['rows_still_waiting'], $answer['also']]);
        self::assertIsString($answer['batch_id']);
        self::assertSame(3, $this->batchNow($answer['batch_id'])['rows_requested']);
        self::assertSame(['said_already_on_profile', 'unusual_time'], $this->wavedThrough($rows['both'][0]));
        $this->work($answer['batch_id']);

        // Added - never sent back a second time for the other question
        self::assertSame(['added', 'added', 'added'], [$this->rowNow($rows['both'][0])['state'], $this->rowNow($rows['both'][1])['state'], $this->rowNow($rows['fast'])['state']]);
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND batch_id = :batch AND failure IS NOT NULL", ['id' => $importId, 'batch' => $answer['batch_id']]));
        // The row that only said "already on MySpeedPuzzling" was not swept in: its own card asks it
        self::assertSame(['waiting', 'said_already_on_profile', []], [$this->rowNow($rows['said'])['state'], $this->rowNow($rows['said'])['failure'], $this->wavedThrough($rows['said'])]);
        // "It is right" is stored for the time verification as a single answer stores it
        self::assertGreaterThanOrEqual(1, $this->pairingCount('SELECT COUNT(*) FROM suspicious_time_confirmation WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testARowAnsweredForOneQuestionWaitsForTheOtherOnly(): void
    {
        [$player, $importId, $rows] = $this->importWithBothDoubts('oncewait');
        $this->addAndWork($importId, $player);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $token = (string) $this->browser->request('GET', '/en/import/' . $importId . '/not-added/times')->filter('[data-testid="import-group-times-form"] input[name="_token"]')->attr('value');
        $answerId = Uuid::uuid7()->toString();

        // "These are my times" alone - the other question is left for later
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', ['_token' => $token, 'request_id' => $answerId, 'code' => 'unusual_time', 'action' => 'add_anyway']);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $answerId);

        // The two rows took the answer and wait for the other question only - they were not sent through an "Add"
        // that could only refuse them again
        foreach ($rows['both'] as $rowId) {
            self::assertSame(['waiting', 'said_already_on_profile', []], [$this->rowNow($rowId)['state'], $this->rowNow($rowId)['failure'], $this->openQuestions($rowId)]);
            self::assertSame(['unusual_time'], $this->wavedThrough($rowId));
        }

        $answer = $this->database->fetchAssociative('SELECT rows_answered, rows_still_waiting, batch_id, answered_row_ids FROM data_import_group_answer WHERE id = :id', ['id' => $answerId]);
        self::assertIsArray($answer);
        self::assertIsString($answer['answered_row_ids']);
        self::assertSame([3, 2, [$rows['fast']]], [$answer['rows_answered'], $answer['rows_still_waiting'], json_decode($answer['answered_row_ids'], true)]);
        self::assertIsString($answer['batch_id']);
        self::assertSame(1, $this->batchNow($answer['batch_id'])['rows_requested']);
        $this->work($answer['batch_id']);
        self::assertSame('added', $this->rowNow($rows['fast'])['state']);

        // Now they are in the group of the other question, three with the row that only had that one - one answer
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/not-added');
        self::assertSame('3', $crawler->filter('[data-testid="import-group-said"]')->attr('data-rows'));
        $saidAnswerId = Uuid::uuid7()->toString();
        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', ['_token' => $token, 'request_id' => $saidAnswerId, 'code' => 'said_already_on_profile', 'action' => 'add_anyway']);
        $second = $this->database->fetchOne('SELECT batch_id FROM data_import_group_answer WHERE id = :id', ['id' => $saidAnswerId]);
        self::assertIsString($second);
        $this->work($second);
        self::assertSame(['added', 'added', 'added'], [$this->rowNow($rows['both'][0])['state'], $this->rowNow($rows['both'][1])['state'], $this->rowNow($rows['said'])['state']]);
    }

    public function testTheOtherAnswerCanBeDoNotImportThose(): void
    {
        [$player, $importId, $rows] = $this->importWithBothDoubts('onceleave');
        $this->addAndWork($importId, $player);
        $answerId = Uuid::uuid7()->toString();

        $this->answerGroup($importId, $player, $answerId, 'unusual_time', 'add_anyway', also: ['said_already_on_profile' => 'leave_out']);

        // Exactly the rows with both doubts are not imported; the one with an unusual time alone is added
        self::assertSame(['left_out', 'left_out'], [$this->rowNow($rows['both'][0])['state'], $this->rowNow($rows['both'][1])['state']]);
        $batchId = $this->database->fetchOne('SELECT batch_id FROM data_import_group_answer WHERE id = :id', ['id' => $answerId]);
        self::assertIsString($batchId);
        self::assertSame(1, $this->batchNow($batchId)['rows_requested']);
        $this->work($batchId);
        self::assertSame(['added', 'waiting'], [$this->rowNow($rows['fast'])['state'], $this->rowNow($rows['said'])['state']]);
    }

    public function testAlreadyOnMySpeedPuzzlingIsCountedBeforeTheAddByTheEnginesOwnQuestion(): void
    {
        [$player, $importId, $notFound, $found, $plain] = $this->importSayingItIsOnTheProfile('oncecount', 4);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // The review holds the block as a frame of its own
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertResponseIsSuccessful();
        self::assertSame('/en/import/' . $importId . '/add/already-on', $crawler->filter('turbo-frame#import-add-said')->attr('src'));

        // The count: the rows the file marks and the engine does not find - not the one that is on the profile
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/add/already-on');
        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-testid="import-add-said"]');
        self::assertSame(['5', '4', '1', '0'], [$block->attr('data-said'), $block->attr('data-not-found'), $block->attr('data-all-checked'), $block->attr('data-stands')]);
        self::assertSame('Your file says these results are on MySpeedPuzzling already, and we did not find them: 4', self::spaced($block->filter('[data-testid="import-add-said-count"]')->text()));
        self::assertSame('They are missing - add them', self::spaced($block->filter('[data-testid="import-add-said-missing"]')->text()));
        self::assertSame("Don't import them", self::spaced($block->filter('[data-testid="import-add-said-leave_out"]')->text()));
        self::assertSame('1', $block->filter('form input[name="before_add"]')->first()->attr('value'));
        $counted = self::getContainer()->get(ImportSaidAlreadyCheck::class)->of($this->importEntity($importId))['rowIds'];
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        // ... and an "Add" without an answer sends back exactly those rows with exactly that question
        $this->addAndWork($importId, $player);
        $refused = $this->database->fetchFirstColumn("SELECT id FROM data_import_row WHERE import_id = :id AND state = 'waiting' AND failure = 'said_already_on_profile' ORDER BY id", ['id' => $importId]);
        sort($counted);
        self::assertSame($refused, $counted);
        self::assertSame($notFound, $refused);
        self::assertSame(['already_yours', 'added'], [$this->rowNow($found)['state'], $this->rowNow($plain)['state']]);
    }

    public function testAlreadyOnMySpeedPuzzlingAnsweredBeforeTheAddIsNotAskedAfterIt(): void
    {
        [$player, $importId, $notFound, $found, $plain] = $this->importSayingItIsOnTheProfile('oncebefore', 3);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $form = $this->browser->request('GET', '/en/import/' . $importId . '/add/already-on')->filter('[data-testid="import-add-said"] form')->first();
        $answerId = (string) $form->filter('input[name="request_id"]')->attr('value');

        $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'request_id' => $answerId,
            'code' => 'said_already_on_profile',
            'action' => 'add_anyway',
            'before_add' => '1',
        ]);
        self::assertResponseRedirects('/en/import/' . $importId . '/group-answers/' . $answerId);
        // Each row is shown to the engine first: never in the request
        self::assertSame([], $this->wavedThrough($notFound[0]));
        self::getContainer()->get(ProcessImportGroupAnswerHandler::class)(new ProcessImportGroupAnswer($answerId));

        // The existing waiver, with the row's fingerprint, on the rows the engine would have sent back - and only them
        foreach ($notFound as $rowId) {
            self::assertSame(['said_already_on_profile'], $this->wavedThrough($rowId));
        }

        self::assertSame([[], []], [$this->wavedThrough($found), $this->wavedThrough($plain)]);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));
        $outcome = $this->browser->request('GET', '/en/import/' . $importId . '/group-answers/' . $answerId)->filter('[data-testid="import-group-answer-outcome"]');
        self::assertSame(['3', '4'], [$outcome->attr('data-answered'), $outcome->attr('data-total')]);

        // The block now says the answer that stands, with "Change" - and the "Add" asks nothing
        $block = $this->browser->request('GET', '/en/import/' . $importId . '/add/already-on')->filter('[data-testid="import-add-said"]');
        self::assertSame(['1', '0'], [$block->attr('data-stands'), $block->attr('data-not-found')]);
        self::assertCount(1, $block->filter('[data-testid="import-add-said-change"]'));
        $this->addAndWork($importId, $player);
        self::assertSame(['added', 'added', 'added', 'already_yours', 'added'], array_map(fn (string $row): string => $this->rowNow($row)['state'], [...$notFound, $found, $plain]));
    }

    public function testDoNotImportThemBeforeTheAddLeavesOutOnlyTheRowsNotFound(): void
    {
        [$player, $importId, $notFound, $found, $plain] = $this->importSayingItIsOnTheProfile('onceout', 2);
        $answerId = Uuid::uuid7()->toString();
        $this->answerGroup($importId, $player, $answerId, 'said_already_on_profile', 'leave_out', beforeAdd: true);

        self::assertSame(['left_out', 'left_out', 'waiting', 'waiting'], array_map(fn (string $row): string => $this->rowNow($row)['state'], [...$notFound, $found, $plain]));
        // "Don't import them" never stands: a later row is asked
        self::assertNull($this->database->fetchOne('SELECT standing_answers FROM data_import WHERE id = :id', ['id' => $importId]));
    }

    public function testAnAnswerForAGroupStandsForRowsAddedLaterInTheSameImportAndForNoOtherImport(): void
    {
        $player = $this->seedPairingPlayer('oncestands');
        $playerId = $player->id->toString();
        $brand = $this->seedBrand('Zqoncestands');
        $first = $this->puzzleWithTypedTimes($player, 'Zq Stands First', $brand);
        $later = $this->puzzleWithTypedTimes($player, 'Zq Stands Later', $brand);
        $afterChange = $this->puzzleWithTypedTimes($player, 'Zq Stands After Change', $brand);
        $this->puzzleWithTypedTimes($player, 'Zq Stands Other', $brand);
        $importId = $this->importedWorkbook($player, [
            ['Zq Stands First', 1000, '2023-04-01', 1711],
            ['Zq Stands Later', 1000, '2023-04-02', 1811],
            ['Zq Stands Later', 1000, '2023-04-03', 10100],
            ['Zq Stands After Change', 1000, '2023-04-04', 1911],
        ]);
        $this->pair($importId);
        $this->match($importId, 'Zq Stands First', $first);
        // Rows of puzzles that are matched later: kept away from the first "Add"
        $this->unmatch($importId, 'Zq Stands Later');
        $this->unmatch($importId, 'Zq Stands After Change');
        [$fastFirst, $fastLater, $editedLater, $fastAfterChange] = array_map(fn (int $row): string => $this->rowId($importId, $row), [2, 3, 4, 5]);
        $this->fileSaysItIsOnMySpeedPuzzling([$fastLater]);

        // The same player's other import, with a time as unusual
        [$other, $otherSheet] = $this->importOf($player);
        $otherImportId = $other->id->toString();
        $otherFast = $this->resultRow($other, $otherSheet, 'Zq Stands Other', 1000, 'Zqoncestands', '2023-05-01', 1750);
        $this->pair($other);
        $this->settleSheets($otherImportId);

        $this->addAndWork($importId, $player);
        self::assertSame('unusual_time', $this->rowNow($fastFirst)['failure']);

        // "Yes, these are my times - add them": for the group - and from here on for the file
        $answerId = Uuid::uuid7()->toString();
        $this->answerGroup($importId, $player, $answerId, 'unusual_time', 'add_anyway');
        self::assertSame(['unusual_time'], array_keys($this->standingAnswers($importId)));
        $batchId = $this->database->fetchOne('SELECT batch_id FROM data_import_group_answer WHERE id = :id', ['id' => $answerId]);
        self::assertIsString($batchId);
        $this->work($batchId);
        self::assertSame('added', $this->rowNow($fastFirst)['state']);
        $confirmedBefore = $this->pairingCount('SELECT COUNT(*) FROM suspicious_time_confirmation WHERE player_id = :id', ['id' => $playerId]);
        self::assertGreaterThanOrEqual(1, $confirmedBefore);

        // More puzzles are matched; one of their rows is corrected to another unusual time meanwhile
        $this->match($importId, 'Zq Stands Later', $later);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerImportRows($importId, $playerId, [$editedLater], ['time' => '0:30:10']));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        TestingLogin::asPlayer($this->browser, $playerId);

        // What the answer covers this time is still said before the "Add", with "Change"
        $stands = $this->browser->request('GET', '/en/import/' . $importId . '/add/times')->filter('[data-testid="import-add-times-stands"]');
        self::assertSame('2', $stands->attr('data-unusual'));
        self::assertSame('Unusual times among the first 2 results: 2. They are added as they are - your answer for this file.', self::spaced($stands->filter('span')->text()));
        self::assertCount(1, $stands->filter('[data-testid="import-add-times-change"]'));
        self::assertCount(0, $this->browser->getCrawler()->filter('[data-testid="import-add-unusual-right"], [data-testid="import-add-times-group"]'));

        $this->addAndWork($importId, $player);

        // The unusual time is not sent back - the row's own waiver was written when it was added, for the row as it
        // reads then (the corrected one too: the answer is to the question, for the file). Its other question -
        // no answer stands for that one - is still asked
        self::assertSame(['waiting', 'said_already_on_profile', ['unusual_time']], [$this->rowNow($fastLater)['state'], $this->rowNow($fastLater)['failure'], $this->wavedThrough($fastLater)]);
        self::assertSame(['added', ['unusual_time']], [$this->rowNow($editedLater)['state'], $this->wavedThrough($editedLater)]);
        // ... and stored for the time verification exactly as a single "It is right" stores it: the scan (unchanged)
        // raises such a result for the moderators with `confirmed_while_saving`
        $confirmation = $this->database->fetchAssociative('SELECT player_id, expected_seconds FROM suspicious_time_confirmation WHERE time_id = :id', ['id' => $this->rowNow($editedLater)['solving_time_id']]);
        self::assertIsArray($confirmation);
        self::assertSame($playerId, $confirmation['player_id']);
        self::assertGreaterThan(1810, $confirmation['expected_seconds']);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND suspicious', ['id' => $playerId]));

        // Another import of the same player is asked its own questions
        $this->addAndWork($otherImportId, $player);
        self::assertSame(['waiting', 'unusual_time'], [$this->rowNow($otherFast)['state'], $this->rowNow($otherFast)['failure']]);
        self::assertSame([], $this->standingAnswers($otherImportId));

        // "Change": asked again from now on - what was added stays
        $token = (string) $this->browser->request('GET', '/en/import/' . $importId . '/add/times')->filter('input[name="_token"]')->first()->attr('value');
        $this->browser->request('POST', '/en/import/' . $importId . '/add/standing-answer', ['_token' => $token, 'code' => 'unusual_time']);
        self::assertResponseRedirects('/en/import/' . $importId . '/add#unusual-times');
        self::assertSame([], $this->standingAnswers($importId));
        $this->match($importId, 'Zq Stands After Change', $afterChange);
        $this->addAndWork($importId, $player);
        self::assertSame(['waiting', 'unusual_time'], [$this->rowNow($fastAfterChange)['state'], $this->rowNow($fastAfterChange)['failure']]);
        self::assertSame('added', $this->rowNow($editedLater)['state']);
    }

    /**
     * @return iterable<string, array{string, int, array<string, mixed>, null|array{string, int, list<string>, bool}, null|string, string}>
     *         the row (day, seconds, more of `parsed`), what is on the profile before (day, seconds, people, first
     *         try) - of the importing player, or of another player ($otherPlayers) - and the refusal
     */
    public static function provideRefusalsNoAnswerCovers(): iterable
    {
        yield 'a similar result on the profile' => ['2024-01-12', 10100, [], ['2024-01-12', 10300, [], false], null, 'similar_result_exists'];
        yield "somebody else's pair result of that day and time" => ['2024-07-07', 10100, ['type' => 'duo', 'people' => ['A friend']], ['2024-07-07', 10100, ['Somebody'], false], 'partner', 'somebody_elses_result'];
        yield 'a first try that is taken' => ['2024-02-02', 10100, ['firstTry' => true], ['2024-01-01', 10300, [], true], null, 'first_try_taken'];
        yield 'faster than anybody puzzles' => ['2024-01-02', 60, [], null, null, 'too_fast'];
        yield 'a pair that names nobody' => ['2024-01-02', 10100, ['type' => 'duo'], null, null, 'people_do_not_fit_type'];
    }

    /**
     * @param array<string, mixed> $more
     * @param null|array{string, int, list<string>, bool} $before
     */
    #[DataProvider('provideRefusalsNoAnswerCovers')]
    public function testWhatIsNeverWaivedIsRefusedWhateverStands(string $day, int $seconds, array $more, null|array $before, null|string $beforeOf, string $refusal): void
    {
        $player = $this->seedPairingPlayer('oncenever');
        $puzzle = $this->seedCataloguePuzzle('Zq Never Waived', 1000, $this->seedBrand('Zqoncenever'));

        if ($before !== null) {
            $this->typedResult($beforeOf === null ? $player : $this->seedPairingPlayer('oncenever-' . $beforeOf), $puzzle, $before[0], $before[1], $before[2], $before[3]);
        }

        [$import, $sheet] = $this->importOf($player);
        $importId = $import->id->toString();
        // The row also carries the question an answer stands for
        $row = $this->resultRow($import, $sheet, 'Zq Never Waived', 1000, 'Zqoncenever', $day, $seconds, [...$more, 'alreadyOnMsp' => true]);
        // ... and so does a row nothing else stands against: it shows that the answers do stand
        $covered = $this->resultRow($import, $sheet, 'Zq Never Waived', 1000, 'Zqoncenever', '2024-03-03', 10111, ['alreadyOnMsp' => true]);
        $this->pair($import);
        $this->settleSheets($importId);
        // Both answers stand for the file
        $this->database->update('data_import', ['standing_answers' => '{"unusual_time": "2026-01-01T00:00:00+00:00", "said_already_on_profile": "2026-01-01T00:00:00+00:00"}'], ['id' => $importId]);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->addAndWork($importId, $player);

        self::assertSame(['added', ['said_already_on_profile']], [$this->rowNow($covered)['state'], $this->wavedThrough($covered)]);
        self::assertSame(['waiting', $refusal, [], null], [$this->rowNow($row)['state'], $this->rowNow($row)['failure'], $this->openQuestions($row), $this->rowNow($row)['solving_time_id']]);
        // No standing answer was written down for a possible duplicate, and none can be asked for
        self::assertSame([], array_values(array_intersect($this->wavedThrough($row), ['similar_result_exists', 'somebody_elses_result'])));
        // On the profile: what was typed before, and the covered row's result - nothing of the refused row
        self::assertCount($before === null || $beforeOf !== null ? 1 : 2, $this->resultsOf($player));
    }

    public function testAnAnswerForPossibleDuplicatesIsNeverTakenAsAGroupNorStands(): void
    {
        $player = $this->seedPairingPlayer('oncedup');
        $puzzle = $this->seedCataloguePuzzle('Zq Once Duplicate', 1000, $this->seedBrand('Zqoncedup'));
        $this->typedResult($player, $puzzle, '2024-01-12', 10300);
        [$import, $sheet] = $this->importOf($player);
        $importId = $import->id->toString();
        $row = $this->resultRow($import, $sheet, 'Zq Once Duplicate', 1000, 'Zqoncedup', '2024-01-12', 10100);
        $this->pair($import);
        $this->settleSheets($importId);
        $this->addAndWork($importId, $player);
        self::assertSame('similar_result_exists', $this->rowNow($row)['failure']);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $token = (string) $this->browser->request('GET', '/en/import/' . $importId . '/not-added')->filter('input[name="_token"]')->first()->attr('value');

        foreach (['similar_result_exists', 'somebody_elses_result'] as $code) {
            $this->browser->request('POST', '/en/import/' . $importId . '/group-answers', ['_token' => $token, 'request_id' => Uuid::uuid7()->toString(), 'code' => $code, 'action' => 'add_anyway']);
            self::assertResponseStatusCodeSame(404);
            $this->browser->request('POST', '/en/import/' . $importId . '/add/standing-answer', ['_token' => $token, 'code' => $code]);
            self::assertResponseStatusCodeSame(404);
        }

        // As the other answer on a card it is dropped too
        $answerId = Uuid::uuid7()->toString();
        $this->database->update('data_import_row', ['failure' => 'unusual_time', 'open_questions' => '["unusual_time", "similar_result_exists"]'], ['id' => $row]);
        $this->answerGroup($importId, $player, $answerId, 'unusual_time', 'add_anyway', also: ['similar_result_exists' => 'add_anyway']);
        self::assertNull($this->database->fetchOne('SELECT also FROM data_import_group_answer WHERE id = :id', ['id' => $answerId]));
        self::assertSame(['similar_result_exists', []], [$this->rowNow($row)['failure'], array_values(array_intersect($this->wavedThrough($row), ['similar_result_exists']))]);
        self::assertSame(['unusual_time'], array_keys($this->standingAnswers($importId)));
    }

    public function testEveryTextOfThisSliceIsThereInEveryLanguage(): void
    {
        $translator = self::getContainer()->get('translator');
        $english = array_keys(array_filter($translator->getCatalogue('en')->all('messages'), static fn (string $key): bool => str_starts_with($key, 'data_import.once.'), ARRAY_FILTER_USE_KEY));
        self::assertGreaterThan(15, count($english));

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            foreach ($english as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key), sprintf('"%s" is missing in %s.', $key, $locale));
            }
        }
    }

    /**
     * A player whose results on a made-up puzzle are around three hours, and an import with: two rows that are much
     * faster **and** marked "already on MySpeedPuzzling" (`both`), one that is only much faster (`fast`), one that is
     * only marked (`said`) and a plain one (`plain`).
     *
     * @return array{Player, string, array{both: list<string>, fast: string, said: string, plain: string}}
     */
    private function importWithBothDoubts(string $name): array
    {
        $player = $this->seedPairingPlayer($name);
        $marker = 'Zq' . ucfirst($name);
        $puzzle = $this->puzzleWithTypedTimes($player, $marker . ' Puzzle', $this->seedBrand($marker . 'brand'));
        $importId = $this->importedWorkbook($player, [
            [$marker . ' Puzzle', 1000, '2023-03-20', 10011],
            [$marker . ' Puzzle', 1000, '2023-03-21', 10111],
            [$marker . ' Puzzle', 1000, '2023-04-01', 1711],
            [$marker . ' Puzzle', 1000, '2023-04-02', 1811],
            [$marker . ' Puzzle', 1000, '2023-04-03', 1911],
        ]);
        $this->pair($importId);
        $this->match($importId, $marker . ' Puzzle', $puzzle);
        $rows = [
            'plain' => $this->rowId($importId, 2),
            'said' => $this->rowId($importId, 3),
            'both' => [$this->rowId($importId, 4), $this->rowId($importId, 5)],
            'fast' => $this->rowId($importId, 6),
        ];
        $this->fileSaysItIsOnMySpeedPuzzling([$rows['said'], ...$rows['both']]);

        return [$player, $importId, $rows];
    }

    /**
     * An import whose file marks $marked results "already on MySpeedPuzzling" that are not on the profile, one marked
     * result that **is** there (typed before, the same day and time) and a plain row.
     *
     * @return array{Player, string, list<string>, string, string} the player, the import, the rows not found, the row found, the plain row
     */
    private function importSayingItIsOnTheProfile(string $name, int $marked): array
    {
        $player = $this->seedPairingPlayer($name);
        $marker = 'Zq' . ucfirst($name);
        $puzzle = $this->seedCataloguePuzzle($marker . ' Puzzle', 500, $this->seedBrand($marker . 'brand'));
        $this->typedResult($player, $puzzle, '2022-06-01', 3500);
        $file = [];

        for ($row = 0; $row < $marked; $row++) {
            $file[] = [$marker . ' Puzzle', 500, sprintf('2022-01-%02d', $row + 1), 3000 + $row];
        }

        $file[] = [$marker . ' Puzzle', 500, '2022-06-01', 3500];
        $file[] = [$marker . ' Puzzle', 500, '2022-02-01', 3050];
        $importId = $this->importedWorkbook($player, $file);
        $this->pair($importId);
        $this->match($importId, $marker . ' Puzzle', $puzzle);
        $notFound = array_map(fn (int $row): string => $this->rowId($importId, $row + 2), range(0, $marked - 1));
        $found = $this->rowId($importId, $marked + 2);
        $this->fileSaysItIsOnMySpeedPuzzling([...$notFound, $found]);
        sort($notFound);

        return [$player, $importId, $notFound, $found, $this->rowId($importId, $marked + 3)];
    }

    /**
     * A made-up puzzle the player has three results of, around three hours - so half an hour is unusual for them.
     */
    private function puzzleWithTypedTimes(Player $player, string $name, Manufacturer $brand): string
    {
        $brand = $this->pairingEntityManager()->find(Manufacturer::class, $brand->id->toString());
        $puzzle = $this->seedCataloguePuzzle($name, 1000, $brand);
        $this->typedResult($player, $puzzle, '2023-01-01', 10801);
        $this->typedResult($player, $puzzle, '2023-02-01', 10501);
        $this->typedResult($player, $puzzle, '2023-03-01', 10201);

        return $puzzle;
    }

    /**
     * A group answer through the bus, gone through at once - as StartImportGroupAnswerController sends a small one.
     *
     * @param array<string, string> $also
     */
    private function answerGroup(string $importId, Player $player, string $answerId, string $code, string $action, array $also = [], bool $beforeAdd = false): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $started = $bus->dispatch(new AnswerImportRowsGroup($answerId, $importId, $player->id->toString(), $code, $action, null, $beforeAdd, $also))->last(HandledStamp::class)?->getResult();
        self::assertIsArray($started);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $bus->dispatch(new ProcessImportGroupAnswer($answerId), [new TransportNamesStamp(['sync'])]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * The rows carry the file's mark "entered on MySpeedPuzzling", as ImportRowParser writes it.
     *
     * @param list<string> $rowIds
     */
    private function fileSaysItIsOnMySpeedPuzzling(array $rowIds): void
    {
        foreach ($rowIds as $rowId) {
            self::assertSame(1, $this->database->executeStatement("UPDATE data_import_row SET parsed = jsonb_set(parsed, '{alreadyOnMsp}', 'true') WHERE id = :id", ['id' => $rowId]));
        }
    }

    private function rowId(string $importId, int $rowNumber): string
    {
        $id = $this->database->fetchOne('SELECT id FROM data_import_row WHERE import_id = :id AND row_number = :row', ['id' => $importId, 'row' => $rowNumber]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * An import of the player read from a made-up workbook by the real handlers: one sheet "Results" with the
     * columns Puzzle, Pieces, Date, Time and the rows given (from row 2 on) - so its rows can be read again
     * ("Don't import", a corrected time).
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

    private function settleSheets(string $importId): void
    {
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = mapping_version WHERE import_id = :id', ['id' => $importId]);
    }

    /**
     * The import's distinct puzzle waits for the player's choice again - its rows are not ready.
     */
    private function unmatch(string $importId, string $name): void
    {
        self::assertSame(1, $this->database->executeStatement("UPDATE data_import_puzzle SET choice = 'undecided', puzzle_id = NULL WHERE import_id = :id AND name = :name", ['id' => $importId, 'name' => $name]));
    }

    private function match(string $importId, string $name, string $puzzleId): void
    {
        self::assertSame(1, $this->database->executeStatement("UPDATE data_import_puzzle SET choice = 'catalogue', puzzle_id = :puzzle WHERE import_id = :id AND name = :name", ['id' => $importId, 'name' => $name, 'puzzle' => $puzzleId]));
    }

    private function importEntity(string $importId): DataImport
    {
        $import = self::getContainer()->get(EntityManagerInterface::class)->find(DataImport::class, $importId);
        self::assertNotNull($import);

        return $import;
    }

    /**
     * @return array<string, string>
     */
    private function standingAnswers(string $importId): array
    {
        $answers = $this->database->fetchOne('SELECT standing_answers FROM data_import WHERE id = :id', ['id' => $importId]);

        /** @var array<string, string> $decoded */
        $decoded = is_string($answers) ? json_decode($answers, true) : [];

        return $decoded;
    }

    /**
     * The other questions a row waits with, as the "Add" wrote them down - the whole list, its reason first.
     *
     * @return list<string>
     */
    private function openQuestions(string $rowId): array
    {
        $questions = $this->database->fetchOne('SELECT open_questions FROM data_import_row WHERE id = :id', ['id' => $rowId]);

        /** @var list<string> $decoded */
        $decoded = is_string($questions) ? json_decode($questions, true) : [];

        return $decoded;
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
            ['said_already_on_profile', 'similar_result_exists', 'somebody_elses_result', 'unusual_time'],
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
