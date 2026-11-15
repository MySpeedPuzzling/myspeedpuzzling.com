<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\AddCustomField;
use SpeedPuzzling\Web\Message\ApplyImportRow;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\ChangeImportAddingPaused;
use SpeedPuzzling\Web\Message\ChangeImportEmails;
use SpeedPuzzling\Web\Message\ContinueImportBatches;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Message\DiscardImport;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\SendImportAddedEmail;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\MessageHandler\DecideImportPuzzlesHandler;
use SpeedPuzzling\Web\MessageHandler\ProcessImportBatchHandler;
use SpeedPuzzling\Web\MessageHandler\SendImportAddedEmailHandler;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Query\GetPlayerActivityCalendar;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\DataImport\ImportRowTimeCheck;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\TestMailerSpy;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Add" (docs/features/data-import/adding.md): the ready rows of an import become results, collection puzzles and
 * wish list puzzles of the player - through the import's own writer, in the background, told once.
 */
final class ImportAddingTest extends KernelTestCase
{
    use ImportAddingScenario;

    private InMemoryTransport $async;
    private InMemoryTransport $imports;

    protected function setUp(): void
    {
        self::bootKernel();
        $async = self::getContainer()->get('messenger.transport.async');
        $imports = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        self::assertInstanceOf(InMemoryTransport::class, $imports);
        $this->async = $async;
        $this->imports = $imports;
    }

    public function testReadyRowsBecomeResultsOfThePlayerAndThePlayerIsToldOnce(): void
    {
        $player = $this->seedPairingPlayer('adder');
        $partner = $this->seedPairingPlayer('partner');
        $brand = $this->seedBrand('Zqadd');
        $solo = $this->seedCataloguePuzzle('Zq Add Lighthouse', 500, $brand);
        $pair = $this->seedCataloguePuzzle('Zq Add Harbour', 1000, $brand);
        $untimed = $this->seedCataloguePuzzle('Zq Add Meadow', 300, $brand);

        [$import, $sheet] = $this->importOf($player);
        $soloRow = $this->resultRow($import, $sheet, 'Zq Add Lighthouse', 500, 'Zqadd', '2024-03-01', 3723, ['firstTry' => true, 'comment' => 'A public comment']);
        $pairRow = $this->resultRow($import, $sheet, 'Zq Add Harbour', 1000, 'Zqadd', '2024-03-02', 5400, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $untimedRow = $this->resultRow($import, $sheet, 'Zq Add Meadow', 300, 'Zqadd', '2024-03-03', null);
        $this->pair($import);

        $outcome = $this->add($import, $player);

        self::assertSame([AddImportRowsOutcome::STARTED, 3, []], [$outcome->status, $outcome->rows, $outcome->skipped]);
        // Nothing is added in the request: the rows are queued, the job is on the imports queue
        self::assertSame([], $this->resultsOf($player));
        self::assertSame('queued', $this->rowNow($soloRow)['state']);
        self::assertCount(1, array_filter($this->imports->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof ProcessImportBatch));

        $this->work($outcome->id);

        $results = $this->resultsOf($player);
        self::assertSame(
            [[$solo, 3723, '2024-03-01 00:00:00', 'solo', true, 'A public comment'], [$pair, 5400, '2024-03-02 00:00:00', 'duo', false, null], [$untimed, null, '2024-03-03 00:00:00', 'solo', false, null]],
            array_map(static fn (array $result): array => [$result['puzzle_id'], $result['seconds_to_solve'], $result['finished_at'], $result['puzzling_type'], $result['first_attempt'], $result['comment']], $results),
        );
        self::assertSame(['spreadsheet_import'], array_values(array_unique(array_map(static fn (array $result): string => self::text($result['created_via']), $results))));
        // Noon of the solve day (UTC for a player without a zone or a country), never "now"
        self::assertStringStartsWith('2024-03-01 12:', self::text($results[0]['tracked_at']));
        // A partner written as a player's code is a guest of that name: an import never puts a result on another
        // registered player's profile (README D12) - the pair has exactly one registered member, the importer
        $team = json_decode(self::text($results[1]['team']), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($team);
        self::assertIsArray($team['puzzlers']);
        self::assertSame(
            [[$player->id->toString(), null], [null, $partner->code]],
            array_map(static fn (mixed $puzzler): array => is_array($puzzler) ? [$puzzler['player_id'] ?? null, $puzzler['player_name'] ?? null] : [], $team['puzzlers']),
        );
        self::assertSame(
            [$player->id->toString()],
            $this->pairingDatabase()->fetchFirstColumn('SELECT member.player_id FROM puzzling_team_member member WHERE member.team_id = (SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id) AND member.player_id IS NOT NULL', ['id' => $results[1]['id']]),
        );
        self::assertSame([], $this->resultsOf($partner));
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM puzzle_solving_time WHERE (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:id AS UUID)))", ['id' => $partner->id->toString()]));

        foreach ([$soloRow, $pairRow, $untimedRow] as $index => $rowId) {
            $row = $this->rowNow($rowId);
            self::assertSame(['added', null, $results[$index]['id'], true, $outcome->id], [$row['state'], $row['failure'], $row['solving_time_id'], $row['result_made'], $row['batch_id']]);
            self::assertSame($results[$index]['puzzle_id'], $this->pairingDatabase()->fetchOne("SELECT made -> 'result' ->> 'puzzleId' FROM data_import_row WHERE id = :id", ['id' => $rowId]));
        }

        $batch = $this->batchNow($outcome->id);
        self::assertSame(['finished', 3, 3, 0, 0, 0, 3], [$batch['state'], $batch['rows_requested'], $batch['rows_added'], $batch['rows_already_yours'], $batch['rows_refused'], $batch['rows_failed'], $batch['results_added']]);
        self::assertSame(TestClock::FROZEN_NOW, $batch['finished_at']);

        // Told once: one notification for the batch, none for anybody else - no follower, no partner
        self::assertSame(
            [['player_id' => $player->id->toString(), 'type' => NotificationType::ImportRowsAdded->value, 'target_data_import_batch_id' => $outcome->id]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT player_id, type, target_data_import_batch_id FROM notification WHERE player_id IN (:a, :b)', ['a' => $player->id->toString(), 'b' => $partner->id->toString()]),
        );

        // What is owed for the results: XP per result - on the import's own queue, never on the shared one -, the
        // importer's achievements once (nobody else is on an imported result), and one e-mail
        $queued = self::messagesOn($this->async);
        self::assertCount(0, array_filter($queued, static fn (object $message): bool => $message instanceof AwardXpForSolvingTime));
        self::assertCount(3, array_filter(self::messagesOn($this->imports), static fn (object $message): bool => $message instanceof AwardXpForSolvingTime));
        self::assertSame(
            [$player->id->toString()],
            array_map(static fn (RecalculateBadgesForPlayer $message): string => $message->playerId, array_values(array_filter($queued, static fn (object $message): bool => $message instanceof RecalculateBadgesForPlayer))),
        );
        self::assertCount(1, array_filter($queued, static fn (object $message): bool => $message instanceof SendImportAddedEmail));

        // The puzzle's statistics know the result
        self::assertSame(1, $this->pairingCount('SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id', ['id' => $solo]));

        // The notifications page reads it
        $notifications = self::getContainer()->get(GetNotifications::class)->forPlayer($player->id->toString(), 10, 0);
        self::assertCount(1, $notifications);
        self::assertTrue($notifications[0]->isImportNotification());
        self::assertSame([$import->id->toString(), 'results.xlsx', 3, 0, 0], [$notifications[0]->importId, $notifications[0]->importFileName, $notifications[0]->importRowsAdded, $notifications[0]->importRowsAlreadyYours, $notifications[0]->importRowsNotAdded]);
    }

    public function testOwnedWishListAndBothRowsBecomeItemsOfThePlayer(): void
    {
        $player = $this->seedPairingPlayer('collector');
        $brand = $this->seedBrand('Zqown');
        $owned = $this->seedCataloguePuzzle('Zq Own Castle', 1000, $brand);
        $wished = $this->seedCataloguePuzzle('Zq Own Dream', 2000, $brand);
        $both = $this->seedCataloguePuzzle('Zq Own Both', 500, $brand);
        $alreadyOwned = $this->seedCataloguePuzzle('Zq Own Already', 500, $brand);
        $this->pairingDatabase()->insert('collection_item', ['id' => Uuid::uuid7()->toString(), 'player_id' => $player->id->toString(), 'puzzle_id' => $alreadyOwned, 'added_at' => '2020-01-01 00:00:00', 'comment' => 'Mine since ever']);

        [$import, $sheet] = $this->importOf($player);
        $ownedRow = $this->ownedRow($import, $sheet, 'Zq Own Castle', 1000, 'Zqown', ['comment' => 'Box a bit torn']);
        $wishRow = $this->wishRow($import, $sheet, 'Zq Own Dream', 2000, 'Zqown');
        $bothRow = $this->resultRow($import, $sheet, 'Zq Own Both', 500, 'Zqown', '2023-05-05', 2400, ['comment' => 'Fun one'], DataImportRowKind::Both);
        $alreadyRow = $this->ownedRow($import, $sheet, 'Zq Own Already', 500, 'Zqown', ['comment' => 'Would overwrite']);
        $wishOfOwnedRow = $this->wishRow($import, $sheet, 'Zq Own Already', 500, 'Zqown');
        $this->pair($import);

        $outcome = $this->addAndWork($import, $player);

        self::assertSame(5, $outcome->rows);
        $items = $this->pairingDatabase()->fetchAllKeyValue('SELECT puzzle_id, comment FROM collection_item WHERE player_id = :id AND collection_id IS NULL', ['id' => $player->id->toString()]);
        // The owned row's comment is the item's; a row that is also a result gave its comment to the result; an item
        // that was there keeps its comment
        self::assertEqualsCanonicalizing([$owned => 'Box a bit torn', $both => null, $alreadyOwned => 'Mine since ever'], $items);
        self::assertSame([$wished], $this->pairingDatabase()->fetchFirstColumn('SELECT puzzle_id FROM wish_list_item WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame('Fun one', $this->resultsOf($player)[0]['comment']);

        self::assertSame(['added', true], [$this->rowNow($ownedRow)['state'], $this->rowNow($ownedRow)['collection_item_id'] !== null]);
        self::assertSame(['added', true], [$this->rowNow($wishRow)['state'], $this->rowNow($wishRow)['wish_list_item_id'] !== null]);
        $bothNow = $this->rowNow($bothRow);
        self::assertSame(['added', true, true], [$bothNow['state'], $bothNow['result_made'], $bothNow['collection_item_id'] !== null]);
        // What was there before is never the row's: nothing recorded, nothing for Undo to take
        self::assertSame(['already_yours', 'owned', null], [$this->rowNow($alreadyRow)['state'], $this->rowNow($alreadyRow)['already_yours_how'], $this->rowNow($alreadyRow)['collection_item_id']]);
        // A puzzle the player owns is nothing to wish for
        self::assertSame(['already_yours', null], [$this->rowNow($wishOfOwnedRow)['state'], $this->rowNow($wishOfOwnedRow)['wish_list_item_id']]);

        $batch = $this->batchNow($outcome->id);
        self::assertSame([3, 2, 1, 2, 1], [$batch['rows_added'], $batch['rows_already_yours'], $batch['results_added'], $batch['collection_items_added'], $batch['wish_list_items_added']]);
    }

    public function testRowsThatAreNotReadyAreNotQueuedAndSaySo(): void
    {
        $player = $this->seedPairingPlayer('not-ready');
        $brand = $this->seedBrand('Zqready');
        $this->seedCataloguePuzzle('Zq Ready Puzzle', 500, $brand);
        $this->seedCataloguePuzzle('Zq Month Puzzle', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $ready = $this->resultRow($import, $sheet, 'Zq Ready Puzzle', 500, 'Zqready', '2024-01-01', 1800);
        // A result without any date waits - the player gives it one (a month or a year is a date: date-precision.md)
        $month = $this->seedRow($import, $sheet, 'Zq Month Puzzle', 500, 'Zqready', ['seconds' => 1900, 'type' => 'solo']);
        // Not in the catalogue: kept as an unlisted puzzle - those arrive with a later release
        $unlisted = $this->resultRow($import, $sheet, 'Zq Nowhere To Be Found At All', 750, 'Zqready', '2024-01-02', 2000);
        // Undecided, and a row with a problem
        $undecided = $this->resultRow($import, $sheet, 'Zq Nobody Decided This One', 750, 'Zqready', '2024-01-03', 2100);
        $this->pair($import);
        $this->pairingDatabase()->update('data_import_row', ['problems' => '[{"field": "seconds", "code": "time_unreadable"}]'], ['id' => $undecided]);
        $unlistedPuzzle = $this->importPuzzle($import, 'Zq Nowhere To Be Found At All');
        $this->handled(self::getContainer()->get(DecideImportPuzzlesHandler::class), new DecideImportPuzzles($import->id->toString(), $player->id->toString(), [
            new ImportPuzzleDecisionChange($unlistedPuzzle['id'], ImportPuzzleChoice::Unlisted, null, $unlistedPuzzle['decision_version']),
        ]));

        $outcome = $this->addAndWork($import, $player);

        self::assertSame([AddImportRowsOutcome::STARTED, 1, ['unlisted_not_yet' => 1, 'needs_exact_day' => 1]], [$outcome->status, $outcome->rows, $outcome->skipped]);
        self::assertSame('added', $this->rowNow($ready)['state']);

        foreach ([$month, $unlisted, $undecided] as $rowId) {
            self::assertSame(['waiting', null, null], [$this->rowNow($rowId)['state'], $this->rowNow($rowId)['batch_id'], $this->rowNow($rowId)['solving_time_id']]);
        }

        self::assertCount(1, $this->resultsOf($player));

        // Asked for by name they are not taken either - and nothing is started
        $again = $this->add($import, $player, [$month, $unlisted, $undecided]);
        self::assertSame([AddImportRowsOutcome::NOTHING, null, ['unlisted_not_yet' => 1, 'needs_exact_day' => 1]], [$again->status, $again->id, $again->skipped]);
    }

    public function testAPuzzleMergedBetweenPairingAndAddingTakesTheResultOnTheSurvivor(): void
    {
        $player = $this->seedPairingPlayer('merged');
        $brand = $this->seedBrand('Zqmerged');
        $survivor = $this->seedCataloguePuzzle('Zq Merged Survivor', 500, $brand);
        $duplicate = $this->seedCataloguePuzzle('Zq Merged Duplicate', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Merged Duplicate', 500, 'Zqmerged', '2024-02-02', 2222);
        $this->pair($import);
        self::assertSame($duplicate, $this->importPuzzle($import, 'Zq Merged Duplicate')['puzzle_id']);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $mergeRequestId = Uuid::uuid7()->toString();
        $bus->dispatch(new SubmitPuzzleMergeRequest(mergeRequestId: $mergeRequestId, sourcePuzzleId: $survivor, reporterId: PlayerFixture::PLAYER_REGULAR, duplicatePuzzleIds: [$duplicate]));
        $bus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: $survivor,
            mergedName: 'Zq Merged Survivor',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: $brand->id->toString(),
            selectedImagePuzzleId: null,
        ));

        $this->addAndWork($import, $player);

        self::assertSame('added', $this->rowNow($row)['state']);
        self::assertSame([$survivor], array_column($this->resultsOf($player), 'puzzle_id'));
    }

    public function testAHiddenPuzzleIsRefusedAndTheRowWaitsAgainWithTheReason(): void
    {
        $player = $this->seedPairingPlayer('hidden');
        $brand = $this->seedBrand('Zqhidden');
        $puzzle = $this->seedCataloguePuzzle('Zq Hidden Later', 500, $brand);
        $fine = $this->seedCataloguePuzzle('Zq Hidden Fine', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $hiddenRow = $this->resultRow($import, $sheet, 'Zq Hidden Later', 500, 'Zqhidden', '2024-02-02', 2222);
        $fineRow = $this->resultRow($import, $sheet, 'Zq Hidden Fine', 500, 'Zqhidden', '2024-02-03', 2333);
        $this->pair($import);
        // Hidden after it was paired: a secret competition puzzle
        $this->pairingDatabase()->update('puzzle', ['hide_until' => '2030-01-01 00:00:00'], ['id' => $puzzle]);

        $outcome = $this->addAndWork($import, $player);

        self::assertSame(['waiting', 'puzzle_not_available', false], [$this->rowNow($hiddenRow)['state'], $this->rowNow($hiddenRow)['failure'], $this->rowNow($hiddenRow)['result_made']]);
        self::assertSame('added', $this->rowNow($fineRow)['state']);
        self::assertSame([$fine], array_column($this->resultsOf($player), 'puzzle_id'));
        self::assertSame([1, 1, 0], [$this->batchNow($outcome->id)['rows_added'], $this->batchNow($outcome->id)['rows_refused'], $this->batchNow($outcome->id)['rows_failed']]);

        // "Add everything that is ready" leaves a refused row alone; asked for by name it is tried again
        self::assertSame(AddImportRowsOutcome::NOTHING, $this->add($import, $player)->status);
        $this->pairingDatabase()->update('puzzle', ['hide_until' => null], ['id' => $puzzle]);
        self::assertSame(1, $this->addAndWork($import, $player, [$hiddenRow])->rows);
        self::assertSame(['added', null], [$this->rowNow($hiddenRow)['state'], $this->rowNow($hiddenRow)['failure']]);
    }

    public function testAFirstTrySomebodyAlreadyHoldsRefusesTheRowAndNothingOfItIsWritten(): void
    {
        $player = $this->seedPairingPlayer('first-try');
        $brand = $this->seedBrand('Zqfirst');
        $puzzle = $this->seedCataloguePuzzle('Zq First Try', 500, $brand);
        $this->typedResult($player, $puzzle, '2022-01-01', 4000, firstTry: true);

        [$import, $sheet] = $this->importOf($player);
        // Also an owned puzzle: a row is all or nothing
        $row = $this->resultRow($import, $sheet, 'Zq First Try', 500, 'Zqfirst', '2023-01-01', 3000, ['firstTry' => true], DataImportRowKind::Both);
        $this->pair($import);

        $this->addAndWork($import, $player);

        $now = $this->rowNow($row);
        self::assertSame(['waiting', 'first_try_taken', false, null], [$now['state'], $now['failure'], $now['result_made'], $now['collection_item_id']]);
        self::assertCount(1, $this->resultsOf($player));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testARowWhosePeopleDoNotFitItsKindIsRefused(): void
    {
        $player = $this->seedPairingPlayer('kind');
        $brand = $this->seedBrand('Zqkind');
        $this->seedCataloguePuzzle('Zq Kind Pair', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        // "Every row of this sheet is a pair result" - and nobody is named: it would be written as a solo result
        $row = $this->resultRow($import, $sheet, 'Zq Kind Pair', 500, 'Zqkind', '2023-01-01', 3000, ['type' => 'duo']);
        $this->pair($import);

        $this->addAndWork($import, $player);

        self::assertSame(['waiting', 'people_do_not_fit_type'], [$this->rowNow($row)['state'], $this->rowNow($row)['failure']]);
        self::assertSame([], $this->resultsOf($player));
    }

    public function testTheValuesOfThePlayersOwnFieldsAreWrittenPrivatelyAndAWrongValueIsLeftOut(): void
    {
        $player = $this->seedPairingPlayer('fields');
        $brand = $this->seedBrand('Zqfields');
        $solved = $this->seedCataloguePuzzle('Zq Fields Solved', 500, $brand);
        $owned = $this->seedCataloguePuzzle('Zq Fields Owned', 1000, $brand);
        // One ready-made field the player has already; the others are added for them
        self::getContainer()->get(MessageBusInterface::class)->dispatch(AddCustomField::readyMade(Uuid::uuid7()->toString(), $player->id->toString(), StandardCustomField::HowILikedIt));

        [$import, $sheet] = $this->importOf($player);
        $resultRow = $this->resultRow($import, $sheet, 'Zq Fields Solved', 500, 'Zqfields', '2024-04-04', 2500, [
            'custom' => ['how_i_liked_it' => 4, 'how_hard_it_felt' => 'not a rating', 'missing_pieces' => 2],
            'notes' => ['with a broken arm'],
        ]);
        $ownedRow = $this->ownedRow($import, $sheet, 'Zq Fields Owned', 1000, 'Zqfields', ['custom' => ['missing_pieces' => 1, 'bought_from' => 'Zq flea market']]);
        $this->pair($import);

        $this->addAndWork($import, $player);

        self::assertSame('added', $this->rowNow($resultRow)['state']);
        $fields = [];

        foreach (['how_i_liked_it', 'private_notes', 'missing_pieces', 'bought_from'] as $key) {
            $fieldId = $this->pairingDatabase()->fetchOne('SELECT id FROM player_custom_field WHERE player_id = :id AND standard_key = :key', ['id' => $player->id->toString(), 'key' => $key]);
            self::assertIsString($fieldId, sprintf('The ready-made field "%s" was added for the player.', $key));
            $fields[$key] = $fieldId;
        }

        $resultId = $this->rowNow($resultRow)['solving_time_id'];
        $onResult = $this->pairingDatabase()->fetchOne('SELECT custom_values FROM solving_time_custom_values WHERE solving_time_id = :time AND player_id = :player', ['time' => $resultId, 'player' => $player->id->toString()]);
        self::assertIsString($onResult);
        // The rating and the words beside the time; "not a rating" is left out, and "missing pieces" is no field of
        // a result
        self::assertEquals([$fields['how_i_liked_it'] => 4, $fields['private_notes'] => 'with a broken arm'], json_decode($onResult, true, flags: JSON_THROW_ON_ERROR));
        $resultFields = $this->rowNow($resultRow)['made']['customFields'] ?? null;
        self::assertIsArray($resultFields);
        self::assertSame(2, $resultFields['leftOut']);
        self::assertIsArray($resultFields['result']);
        self::assertCount(2, $resultFields['result']);

        $onItem = $this->pairingDatabase()->fetchOne('SELECT custom_values FROM collection_item WHERE id = :id', ['id' => $this->rowNow($ownedRow)['collection_item_id']]);
        self::assertIsString($onItem);
        self::assertEquals([$fields['missing_pieces'] => 1, $fields['bought_from'] => 'Zq flea market'], json_decode($onItem, true, flags: JSON_THROW_ON_ERROR));

        // The row is added whatever became of its values
        self::assertSame([$solved], array_column($this->resultsOf($player), 'puzzle_id'));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id AND puzzle_id = :puzzle', ['id' => $player->id->toString(), 'puzzle' => $owned]));
    }

    public function testWhileAddingIsPausedNothingStartsAndARunningBatchWaits(): void
    {
        $player = $this->seedPairingPlayer('paused');
        $brand = $this->seedBrand('Zqpaused');
        $this->seedCataloguePuzzle('Zq Paused One', 500, $brand);
        $this->seedCataloguePuzzle('Zq Paused Two', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $one = $this->resultRow($import, $sheet, 'Zq Paused One', 500, 'Zqpaused', '2024-01-01', 1800);
        $two = $this->resultRow($import, $sheet, 'Zq Paused Two', 500, 'Zqpaused', '2024-01-02', 1900);
        $this->pair($import);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // A batch is running when the switch is flipped: it waits where it is
        $outcome = $this->add($import, $player);
        $bus->dispatch(new ChangeImportAddingPaused(true, PlayerFixture::PLAYER_ADMIN, 'Wrong results reported'));
        $this->work($outcome->id, turns: 3);

        self::assertSame(['running', 'queued', 'queued'], [$this->batchNow($outcome->id)['state'], $this->rowNow($one)['state'], $this->rowNow($two)['state']]);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame(
            [['adding_paused' => true, 'adding_paused_changed_by' => PlayerFixture::PLAYER_ADMIN, 'adding_paused_reason' => 'Wrong results reported', 'adding_paused_changed_at' => TestClock::FROZEN_NOW]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT adding_paused, adding_paused_changed_by, adding_paused_reason, adding_paused_changed_at FROM data_import_settings'),
        );

        // Nothing new starts - for anybody
        $other = $this->seedPairingPlayer('paused-other');
        [$otherImport, $otherSheet] = $this->importOf($other);
        $this->resultRow($otherImport, $otherSheet, 'Zq Paused One', 500, 'Zqpaused', '2024-01-01', 1800);
        $this->pair($otherImport);
        $refused = $this->add($otherImport, $other);
        self::assertSame([AddImportRowsOutcome::PAUSED, null], [$refused->status, $refused->id]);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state <> :waiting', ['id' => $otherImport->id->toString(), 'waiting' => 'waiting']));

        // The sweeper leaves a paused batch alone
        $this->pairingDatabase()->update('data_import_batch', ['last_activity_at' => '2026-10-13 00:00:00'], ['id' => $outcome->id]);
        $this->imports->reset();
        $bus->dispatch(new ContinueImportBatches());
        self::assertSame([], $this->imports->getSent());

        // On again: the sweeper hands the standing batch to the worker, which goes on where the rows say
        $bus->dispatch(new ChangeImportAddingPaused(false, PlayerFixture::PLAYER_ADMIN, null));
        $bus->dispatch(new ContinueImportBatches());
        $sent = self::messagesOn($this->imports);
        self::assertCount(1, $sent);
        self::assertInstanceOf(ProcessImportBatch::class, $sent[0]);
        // A new turn: the job that waited while adding was paused is void
        self::assertSame([$outcome->id, 2], [$sent[0]->batchId, $sent[0]->turn]);
        self::getContainer()->get(ProcessImportBatchHandler::class)(new ProcessImportBatch((string) $outcome->id, 1));
        self::assertSame('queued', $this->rowNow($one)['state']);
        // ... and the sweeper leaves alone a batch of which a turn was just queued
        $this->imports->reset();
        $bus->dispatch(new ContinueImportBatches());
        self::assertSame([], self::messagesOn($this->imports));

        $this->work($outcome->id);
        self::assertSame(['finished', 'added', 'added'], [$this->batchNow($outcome->id)['state'], $this->rowNow($one)['state'], $this->rowNow($two)['state']]);
        self::assertCount(2, $this->resultsOf($player));
    }

    public function testAnotherPlayerCanNotAddSomebodysImport(): void
    {
        $player = $this->seedPairingPlayer('owner');
        $stranger = $this->seedPairingPlayer('stranger');
        $this->seedCataloguePuzzle('Zq Owner Puzzle', 500, $this->seedBrand('Zqowner'));

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Owner Puzzle', 500, 'Zqowner', '2024-01-01', 1800);
        $this->pair($import);

        try {
            $this->add($import, $stranger);
            self::fail('Somebody else\'s import is "not found".');
        } catch (DataImportNotFound) {
            // An http exception arrives as it is (UnwrapHttpExceptionMiddleware)
        }

        self::assertSame('waiting', $this->rowNow($row)['state']);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch'));
        self::assertSame([], [...$this->resultsOf($player), ...$this->resultsOf($stranger)]);
    }

    public function testOneAddOfAPlayerAtATimeAndTheDailyGuardLeavesTheRestForTomorrow(): void
    {
        $player = $this->seedPairingPlayer('guard');
        $brand = $this->seedBrand('Zqguard');
        $this->seedCataloguePuzzle('Zq Guard Puzzle', 500, $brand);
        $this->seedCataloguePuzzle('Zq Guard Owned', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $first = $this->resultRow($import, $sheet, 'Zq Guard Puzzle', 500, 'Zqguard', '2024-01-01', 1800);
        $second = $this->resultRow($import, $sheet, 'Zq Guard Puzzle', 500, 'Zqguard', '2024-01-02', 1900);
        $owned = $this->ownedRow($import, $sheet, 'Zq Guard Owned', 500, 'Zqguard');
        $this->pair($import);

        $running = $this->add($import, $player, [$first]);
        self::assertSame(AddImportRowsOutcome::ALREADY_RUNNING, $this->add($import, $player, [$second])->status);
        $this->work($running->id);

        // 1,999 results were imported earlier today: one more fits, the next waits for tomorrow - owned puzzles are
        // not counted
        $this->pairingDatabase()->executeStatement(<<<SQL
INSERT INTO data_import_row (id, import_id, sheet_id, row_number, first_seen_version, last_seen_version, kind, state, add_as_separate, result_made, added_at, solving_time_id)
SELECT gen_random_uuid(), :importId, :sheetId, 100000 + n, 1, 1, 'result', 'added', false, true, :now, gen_random_uuid()
FROM generate_series(1, 1998) AS n
SQL, ['importId' => $import->id->toString(), 'sheetId' => $sheet->id->toString(), 'now' => TestClock::FROZEN_NOW]);
        $third = $this->resultRow($import, $sheet, 'Zq Guard Puzzle', 500, 'Zqguard', '2024-01-03', 2000);
        $this->pair($import);

        $outcome = $this->addAndWork($import, $player);

        self::assertSame([2, ['over_daily_limit' => 1]], [$outcome->rows, $outcome->skipped]);
        self::assertSame(['added', 'waiting', 'added'], [$this->rowNow($second)['state'], $this->rowNow($third)['state'], $this->rowNow($owned)['state']]);
    }

    public function testTheEmailIsSentOncePerBatchAndNotWhenSwitchedOff(): void
    {
        $container = self::getContainer();
        $player = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        $brand = $this->seedBrand('Zqmail');
        $this->seedCataloguePuzzle('Zq Mail Puzzle', 500, $brand);
        $this->seedCataloguePuzzle('Zq Mail Other', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Mail Puzzle', 500, 'Zqmail', '2024-01-01', 1800);
        $later = $this->resultRow($import, $sheet, 'Zq Mail Other', 500, 'Zqmail', '2024-01-02', 1900);
        $this->pair($import);
        $firstRow = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_row WHERE import_id = :id AND id <> :later', ['id' => $import->id->toString(), 'later' => $later]);
        self::assertIsString($firstRow);

        $mailer = new TestMailerSpy();
        $handler = new SendImportAddedEmailHandler(
            $container->get(DataImportBatchRepository::class),
            $container->get(PlayerAccountEmail::class),
            $container->get(EmailPreferencesLinkGenerator::class),
            $container->get(ImportEmailsUnsubscribeUrl::class),
            $container->get(UrlGeneratorInterface::class),
            $mailer,
            $container->get(TranslatorInterface::class),
        );

        $outcome = $this->addAndWork($import, PlayerFixture::PLAYER_REGULAR, [$firstRow]);
        $emails = array_values(array_filter(self::messagesOn($this->async), static fn (object $message): bool => $message instanceof SendImportAddedEmail));
        self::assertCount(1, $emails);
        $handler($emails[0]);

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame('emails/import_added.html.twig', $email->getHtmlTemplate());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertStringContainsString('/import-emails/unsubscribe/' . PlayerFixture::PLAYER_REGULAR, (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertStringContainsString('/import/' . $import->id->toString(), self::text($email->getContext()['importUrl'] ?? null));
        self::assertSame([1, 1, 0, 0], [$email->getContext()['added'], $email->getContext()['results'], $email->getContext()['alreadyYours'], $email->getContext()['notAdded']]);
        self::assertSame($outcome->id, $emails[0]->batchId);

        // Switched off: the next batch is told in the app only - and a mail queued before the switch is not sent
        $container->get(MessageBusInterface::class)->dispatch(new ChangeImportEmails(PlayerFixture::PLAYER_REGULAR, false));
        $handler($emails[0]);
        self::assertSame(1, $mailer->sentCount());

        $this->async->reset();
        $this->addAndWork($import, PlayerFixture::PLAYER_REGULAR, [$later]);
        self::assertSame([], array_filter($this->async->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof SendImportAddedEmail));
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id AND type = :type', ['id' => PlayerFixture::PLAYER_REGULAR, 'type' => NotificationType::ImportRowsAdded->value]));
    }

    public function testAnUnusualTimeNeedsThePlayersWordAndTheScanIsToldTheyWereAsked(): void
    {
        $player = $this->seedPairingPlayer('unusual');
        $brand = $this->seedBrand('Zqunusual');
        $puzzle = $this->seedCataloguePuzzle('Zq Unusual Puzzle', 1000, $brand);
        // The player's own times are the evidence: around three hours for this puzzle
        $this->typedResult($player, $puzzle, '2023-01-01', 10800);
        $this->typedResult($player, $puzzle, '2023-02-01', 10500);
        $this->typedResult($player, $puzzle, '2023-03-01', 10200);

        [$import, $sheet] = $this->importOf($player);
        // "0:28:20" where the hours were left out - and a time like the player's others
        $unusual = $this->resultRow($import, $sheet, 'Zq Unusual Puzzle', 1000, 'Zqunusual', '2023-04-01', 1700);
        $usual = $this->resultRow($import, $sheet, 'Zq Unusual Puzzle', 1000, 'Zqunusual', '2023-05-01', 10000);
        $this->pair($import);

        // What the screens show before "Add" (6b-2): the rows the player will be asked about
        [$importNow] = $this->reloadImport($import);
        $asked = self::getContainer()->get(ImportRowTimeCheck::class)->unusualAmong($importNow, [$unusual, $usual]);
        self::assertSame([$unusual], array_keys($asked));
        self::assertTrue($asked[$unusual]->isRaised());

        $this->addAndWork($import, $player);

        self::assertSame(['waiting', 'unusual_time'], [$this->rowNow($unusual)['state'], $this->rowNow($unusual)['failure']]);
        self::assertSame('added', $this->rowNow($usual)['state']);
        self::assertCount(4, $this->resultsOf($player));

        // "It is right": added, and stored the way the add form's answer is - the scan tells the moderator
        $this->waive($unusual, 'unusual_time');
        $this->addAndWork($import, $player, [$unusual]);

        self::assertSame('added', $this->rowNow($unusual)['state']);
        self::assertSame(
            [['time_id' => $this->rowNow($unusual)['solving_time_id'], 'player_id' => $player->id->toString()]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT time_id, player_id FROM suspicious_time_confirmation WHERE player_id = :id', ['id' => $player->id->toString()]),
        );
        // Nothing is marked by an import: the result counts like any other until a moderator says otherwise
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND suspicious', ['id' => $player->id->toString()]));
    }

    public function testThePauseStopsARunningBatchWithinARow(): void
    {
        $player = $this->seedPairingPlayer('within-a-row');
        $brand = $this->seedBrand('Zqwithin');
        $this->seedCataloguePuzzle('Zq Within Puzzle', 500, $brand);

        [$import, $sheet] = $this->importOf($player);

        for ($day = 1; $day <= 6; $day++) {
            $this->resultRow($import, $sheet, 'Zq Within Puzzle', 500, 'Zqwithin', sprintf('2024-01-%02d', $day), 5000 + $day);
        }

        $this->pair($import);
        $outcome = $this->add($import, $player);
        self::assertNotNull($outcome->id);

        // A bus that flips the switch right after the second row is added - in the middle of a turn of 50 rows
        $container = self::getContainer();
        $bus = new class ($container->get(MessageBusInterface::class)) implements MessageBusInterface {
            private int $added = 0;

            public function __construct(
                private readonly MessageBusInterface $inner,
            ) {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = $this->inner->dispatch($message, $stamps);

                if ($message instanceof ApplyImportRow && $message->settleOnly === false && ++$this->added === 2) {
                    $this->inner->dispatch(new ChangeImportAddingPaused(true, PlayerFixture::PLAYER_ADMIN, 'Stop'));
                }

                return $envelope;
            }
        };
        $handler = new ProcessImportBatchHandler(
            $bus,
            $container->get(DataImportRowRepository::class),
            $container->get(DataImportSettingsRepository::class),
            $this->pairingEntityManager(),
            $container->get(ImportProgress::class),
            new NullLogger(),
        );

        $handler($this->currentTurnOf($outcome->id));

        self::assertCount(2, $this->resultsOf($player));
        self::assertSame(4, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE batch_id = :id AND state = 'queued'", ['id' => $outcome->id]));
        self::assertSame('running', $this->batchNow($outcome->id)['state']);
    }

    public function testNotesBesideATimeBecomePrivateNotesAlsoWithoutAnyOtherFieldValue(): void
    {
        $player = $this->seedPairingPlayer('notes');
        $this->seedCataloguePuzzle('Zq Notes Puzzle', 500, $this->seedBrand('Zqnotes'));

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Notes Puzzle', 500, 'Zqnotes', '2024-04-04', 5000, ['notes' => ['slower than last time']]);
        $this->pair($import);

        $this->addAndWork($import, $player);

        $fieldId = $this->pairingDatabase()->fetchOne("SELECT id FROM player_custom_field WHERE player_id = :id AND standard_key = 'private_notes'", ['id' => $player->id->toString()]);
        self::assertIsString($fieldId);
        $values = $this->pairingDatabase()->fetchOne('SELECT custom_values FROM solving_time_custom_values WHERE solving_time_id = :time', ['time' => $this->rowNow($row)['solving_time_id']]);
        self::assertIsString($values);
        self::assertEquals([$fieldId => 'slower than last time'], json_decode($values, true, flags: JSON_THROW_ON_ERROR));
        // Private: the public comment of the result stays empty
        self::assertNull($this->resultsOf($player)[0]['comment']);
    }

    public function testAnImportedResultHasNoHourOfDay(): void
    {
        $player = $this->seedPairingPlayer('hours');
        $puzzle = $this->seedCataloguePuzzle('Zq Hours Puzzle', 500, $this->seedBrand('Zqhours'));
        $this->pairingDatabase()->update('player', ['timezone' => 'UTC'], ['id' => $player->id->toString()]);
        $typed = $this->typedResult($player, $puzzle, '2026-10-10', 5100);

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Hours Puzzle', 500, 'Zqhours', '2026-10-11', 5000);
        $this->pair($import);
        $this->addAndWork($import, $player);
        $imported = $this->rowNow($row)['solving_time_id'];

        // Both "logged" at 23:30 of their solve day - the import by whatever bends its made-up moment (a result solved
        // today is tracked "a second ago", at any hour of the night)
        $this->pairingDatabase()->update('puzzle_solving_time', ['tracked_at' => '2026-10-10 23:30:00'], ['id' => $typed]);
        $this->pairingDatabase()->update('puzzle_solving_time', ['tracked_at' => '2026-10-11 23:30:00'], ['id' => $imported]);

        // Night Owl counts the typed one only
        self::assertSame(1, self::getContainer()->get(GetPlayerStatsSnapshot::class)->forPlayer($player->id->toString())->nightOwlSolves);
        // ... and so does the hour-of-day chart
        $hours = self::getContainer()->get(GetPlayerActivityCalendar::class)->hourOfDayBucketsInMonth($player->id->toString(), 2026, 10);
        self::assertSame(1, array_sum($hours));
        self::assertSame(1, $hours[23]);
    }

    /**
     * @return list<object>
     */
    private static function messagesOn(InMemoryTransport $transport): array
    {
        $messages = [];

        foreach ($transport->getSent() as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public function testAnImportThatAddedRowsCanNotBeDiscarded(): void
    {
        $player = $this->seedPairingPlayer('discard');
        $this->seedCataloguePuzzle('Zq Discard Puzzle', 500, $this->seedBrand('Zqdiscard'));

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Discard Puzzle', 500, 'Zqdiscard', '2024-01-01', 1800);
        $this->pair($import);
        $this->addAndWork($import, $player);

        try {
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new DiscardImport($import->id->toString(), $player->id->toString()));
            self::fail('An import that added rows is kept.');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('can not be discarded', $refused->getMessage() . ($refused->getPrevious()?->getMessage() ?? ''));
        }

        // The row and its link are what "never twice" and Undo rest on
        self::assertSame('added', $this->rowNow($row)['state']);
    }
}
