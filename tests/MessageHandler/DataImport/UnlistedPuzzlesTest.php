<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;
use SpeedPuzzling\Web\Message\AnswerUnlistedSuggestion;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\ProcessUnlistedPairing;
use SpeedPuzzling\Web\Message\RemoveUnlistedPuzzle;
use SpeedPuzzling\Web\Message\SuggestUnlistedPuzzlePairings;
use SpeedPuzzling\Web\MessageHandler\AnswerUnlistedSuggestionHandler;
use SpeedPuzzling\Web\MessageHandler\RemoveUnlistedPuzzleHandler;
use SpeedPuzzling\Web\MessageHandler\SuggestUnlistedPuzzlePairingsHandler;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingOutcome;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Unlisted puzzles (docs/features/data-import/unlisted-puzzles.md): rows of a spreadsheet whose puzzle is not in the
 * catalogue are kept in tables of their own - never in `puzzle`, `puzzle_solving_time`, `collection_item` or
 * `wish_list_item` - until the player pairs the puzzle with a catalogue puzzle; then they become real ones through the
 * import's own writer, never twice, and the pairing can be taken back.
 */
final class UnlistedPuzzlesTest extends KernelTestCase
{
    use UnlistedScenario;

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

    public function testRowsOfAnUnlistedPuzzleGoIntoTheUnlistedTablesAndNowhereElse(): void
    {
        $player = $this->seedPairingPlayer('unlisted');
        $brand = $this->seedBrand('Zqunl');
        $listed = $this->seedCataloguePuzzle('Zq Unl Listed Meadow', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $timed = $this->resultRow($import, $sheet, 'Zq Unl Nowhere Harbour', 750, 'Zqunl', '2024-01-02', 2000, ['firstTry' => true, 'comment' => 'Words for later', 'pictureUrl' => 'https://example.test/harbour.jpg']);
        $untimed = $this->resultRow($import, $sheet, 'Zq Unl Nowhere Harbour', 750, 'Zqunl', '2024-01-05', null);
        $pair = $this->resultRow($import, $sheet, 'Zq Unl Nowhere Harbour', 750, 'Zqunl', '2024-01-07', 3000, ['type' => 'duo', 'people' => ['Anna']]);
        // Known to the month only: a catalogue row would wait for the date precision - an unlisted one keeps what the file says
        $month = $this->monthRow($import, $sheet, 'Zq Unl Nowhere Harbour', 750, 'Zqunl', '2021-09', 1900);
        $owned = $this->ownedRow($import, $sheet, 'Zq Unl Nowhere Harbour', 750, 'Zqunl', ['comment' => 'Box a bit torn', 'collection' => 'Shelf B']);
        $wish = $this->wishRow($import, $sheet, 'Zq Unl Nowhere Wish', 1000, 'Zqunl');
        $both = $this->resultRow($import, $sheet, 'Zq Unl Nowhere Both', 300, 'Zqunl', '2023-05-05', 2400, [], DataImportRowKind::Both);
        $onProfile = $this->resultRow($import, $sheet, 'Zq Unl Listed Meadow', 500, 'Zqunl', '2024-02-02', 1800);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Unl Nowhere Harbour', 'Zq Unl Nowhere Wish', 'Zq Unl Nowhere Both');

        $before = $this->publicCounts();
        $outcome = $this->addAndWork($import, $player);

        self::assertSame([AddImportRowsOutcome::STARTED, 8, []], [$outcome->status, $outcome->rows, $outcome->skipped]);
        $after = $this->publicCounts();
        // The one row of a catalogue puzzle made the one result; nothing unlisted touched any public table
        self::assertSame(
            ['puzzles' => $before['puzzles'], 'results' => $before['results'] + 1, 'collectionItems' => $before['collectionItems'], 'wishListItems' => $before['wishListItems']],
            $after,
        );
        self::assertSame([$listed], array_column($this->resultsOf($player), 'puzzle_id'));

        $harbour = $this->unlistedPuzzle($player, 'Zq Unl Nowhere Harbour');
        self::assertSame(['Zqunl', 750, 'https://example.test/harbour.jpg', null], [$harbour['brand_text'], $harbour['pieces_count'], $harbour['picture_url'], $harbour['paired_at']]);
        self::assertSame(
            [
                [2000, '2024-01-02', 'day', 'solo', '[]', true, 'Words for later', $timed],
                [null, '2024-01-05', 'day', 'solo', '[]', null, null, $untimed],
                [3000, '2024-01-07', 'day', 'duo', '["Anna"]', null, null, $pair],
                [1900, '2021-09', 'month', 'solo', '[]', null, null, $month],
            ],
            array_map(static fn (array $result): array => [$result['seconds_to_solve'], $result['solved_on'], $result['date_precision'], $result['puzzling_type'], $result['people'], $result['first_attempt'], $result['comment'], $result['import_row_id']], $this->unlistedResults($harbour['id'])),
        );
        self::assertSame([['owned', 'Box a bit torn', false]], array_map(static fn (array $mark): array => [$mark['kind'], $mark['comment'], $mark['converted']], $this->unlistedMarks($harbour['id'])));
        self::assertSame('Shelf B', $this->pairingDatabase()->fetchOne('SELECT collection_label FROM unlisted_ownership WHERE unlisted_puzzle_id = :id', ['id' => $harbour['id']]));

        $wished = $this->unlistedPuzzle($player, 'Zq Unl Nowhere Wish');
        self::assertSame([], $this->unlistedResults($wished['id']));
        self::assertSame(['wish_list'], array_column($this->unlistedMarks($wished['id']), 'kind'));

        $bothPuzzle = $this->unlistedPuzzle($player, 'Zq Unl Nowhere Both');
        self::assertCount(1, $this->unlistedResults($bothPuzzle['id']));
        self::assertSame(['owned'], array_column($this->unlistedMarks($bothPuzzle['id']), 'kind'));

        // The rows are added - and name nothing of the profile: what they made is on them, by id
        foreach ([$timed, $untimed, $pair, $month, $owned, $wish, $both] as $rowId) {
            $row = $this->rowNow($rowId);
            self::assertSame(['added', null, null, false, null, null], [$row['state'], $row['failure'], $row['solving_time_id'], $row['result_made'], $row['collection_item_id'], $row['wish_list_item_id']], $rowId);
            self::assertIsArray($row['made']);
            self::assertArrayHasKey('unlisted', $row['made']);
        }

        self::assertSame($listed, $this->resultsOf($player)[0]['puzzle_id']);
        self::assertTrue($this->rowNow($onProfile)['result_made']);

        // Nothing is owed for an unlisted result: one XP award (the catalogue row's), and the player is told of the
        // batch once - nothing else, nobody else
        self::assertCount(1, array_filter(self::messagesOn($this->imports), static fn (object $message): bool => $message instanceof AwardXpForSolvingTime));
        self::assertCount(0, array_filter(self::messagesOn($this->async), static fn (object $message): bool => $message instanceof AwardXpForSolvingTime));
        self::assertSame(
            [NotificationType::ImportRowsAdded->value],
            $this->pairingDatabase()->fetchFirstColumn('SELECT type FROM notification WHERE player_id = :id', ['id' => $player->id->toString()]),
        );
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM suspicious_time_confirmation WHERE player_id = :id', ['id' => $player->id->toString()]));

        $batch = $this->batchNow($outcome->id);
        self::assertSame(['finished', 8, 0, 0, 1], [$batch['state'], $batch['rows_added'], $batch['rows_refused'], $batch['rows_failed'], $batch['results_added']]);
    }

    public function testTheSameDataAgainIsAlreadyYoursAndUndoTakesTheUnlistedThingsBack(): void
    {
        $player = $this->seedPairingPlayer('unlisted-twice');
        $this->seedBrand('Zqtwice');

        [$import, $sheet] = $this->importOf($player);
        $result = $this->resultRow($import, $sheet, 'Zq Twice Nowhere', 500, 'Zqtwice', '2024-03-03', 2222);
        // Equal rows as ImportEqualRows leaves them: numbered, the second linked to the one that stands for them
        $equal = $this->resultRow($import, $sheet, 'Zq Twice Nowhere', 500, 'Zqtwice', '2024-03-03', 2222, ['occurrence' => 2]);
        $owned = $this->ownedRow($import, $sheet, 'Zq Twice Nowhere', 500, 'Zqtwice');
        $ownedAgain = $this->ownedRow($import, $sheet, 'Zq Twice Nowhere', 500, 'Zqtwice');
        $this->pairingDatabase()->update('data_import_row', ['same_as_row_id' => $result], ['id' => $equal]);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Twice Nowhere');

        $first = $this->addAndWork($import, $player);
        $unlisted = $this->unlistedPuzzle($player, 'Zq Twice Nowhere');

        // Equal rows of a file are one result (README D6), two rows that own the puzzle one mark
        self::assertCount(1, $this->unlistedResults($unlisted['id']));
        self::assertCount(1, $this->unlistedMarks($unlisted['id']));
        self::assertSame(['already_yours', 'equal_row'], [$this->rowNow($equal)['state'], $this->rowNow($equal)['already_yours_how']]);
        self::assertSame(['already_yours', 'owned'], [$this->rowNow($ownedAgain)['state'], $this->rowNow($ownedAgain)['already_yours_how']]);

        // The same file again: the puzzle arrives decided as before, every row is the player's already
        [$again, $againSheet] = $this->importOf($player);
        $resultAgain = $this->resultRow($again, $againSheet, 'Zq Twice Nowhere', 500, 'Zqtwice', '2024-03-03', 2222);
        $ownedInTheSecond = $this->ownedRow($again, $againSheet, 'Zq Twice Nowhere', 500, 'Zqtwice');
        $this->pair($again);
        self::assertSame('unlisted', $this->importPuzzle($again, 'Zq Twice Nowhere')['choice']);

        $second = $this->addAndWork($again, $player);
        self::assertSame(2, $second->rows);
        self::assertSame(['already_yours', 'earlier_import'], [$this->rowNow($resultAgain)['state'], $this->rowNow($resultAgain)['already_yours_how']]);
        self::assertSame(['already_yours', 'owned'], [$this->rowNow($ownedInTheSecond)['state'], $this->rowNow($ownedInTheSecond)['already_yours_how']]);
        self::assertCount(1, $this->unlistedResults($unlisted['id']));
        self::assertCount(1, $this->unlistedMarks($unlisted['id']));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM unlisted_puzzle WHERE player_id = :id', ['id' => $player->id->toString()]));

        // Undo of the first "Add": its unlisted result, its mark and - nothing hanging on it any more - the puzzle go
        $undo = $this->undo($import, $player, DataImportUndoScope::Batch, $first->id);
        self::assertSame(AddImportRowsOutcome::STARTED, $undo->status);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_puzzle WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_result WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_ownership WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(['waiting', null], [$this->rowNow($result)['state'], $this->rowNow($result)['made']]);
        self::assertSame('waiting', $this->rowNow($owned)['state']);

        // … and they can be added again
        $this->addAndWork($import, $player);
        $unlistedAgain = $this->unlistedPuzzle($player, 'Zq Twice Nowhere');
        self::assertCount(1, $this->unlistedResults($unlistedAgain['id']));
        self::assertCount(1, $this->unlistedMarks($unlistedAgain['id']));
    }

    public function testPairingTurnsUnlistedResultsIntoRealOnesOnceAndKeepsWhatWouldBeAQuestion(): void
    {
        $player = $this->seedPairingPlayer('pairing');
        $brand = $this->seedBrand('Zqpair');
        $catalogue = $this->seedCataloguePuzzle('Zq Pair Catalogue Harbour', 750, $brand);
        $wishTarget = $this->seedCataloguePuzzle('Zq Pair Catalogue Dream', 1000, $brand);
        // What the player typed by hand before: one result the file also has, one that looks like a row of the file
        $typedEqual = $this->typedResult($player, $catalogue, '2024-01-09', 2500);
        $typedSimilar = $this->typedResult($player, $catalogue, '2024-01-12', 2700);

        [$import, $sheet] = $this->importOf($player);
        $new = $this->resultRow($import, $sheet, 'Zq Pair My Harbour Copy', 750, 'Zqpair', '2024-01-02', 2400, ['comment' => 'Public words']);
        $untimed = $this->resultRow($import, $sheet, 'Zq Pair My Harbour Copy', 750, 'Zqpair', '2024-01-05', null);
        $equal = $this->resultRow($import, $sheet, 'Zq Pair My Harbour Copy', 750, 'Zqpair', '2024-01-09', 2500);
        $similar = $this->resultRow($import, $sheet, 'Zq Pair My Harbour Copy', 750, 'Zqpair', '2024-01-12', 2600);
        $month = $this->monthRow($import, $sheet, 'Zq Pair My Harbour Copy', 750, 'Zqpair', '2021-09', 2300);
        $owned = $this->ownedRow($import, $sheet, 'Zq Pair My Harbour Copy', 750, 'Zqpair', ['comment' => 'Box a bit torn']);
        $wish = $this->wishRow($import, $sheet, 'Zq Pair My Dream Copy', 1000, 'Zqpair');
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Pair My Harbour Copy', 'Zq Pair My Dream Copy');
        $batch = $this->addAndWork($import, $player);
        $unlisted = $this->unlistedPuzzle($player, 'Zq Pair My Harbour Copy');
        self::assertCount(2, $this->resultsOf($player, $catalogue));

        $requestId = '0193f000-0000-7000-8000-000000000071';
        $outcome = $this->pairUnlisted($player, $unlisted['id'], $catalogue, $requestId, work: false);

        // Nothing is converted in the request: the pairing is recorded, the job is on the imports queue
        self::assertSame(UnlistedPairingOutcome::STARTED, $outcome->status);
        self::assertCount(2, $this->resultsOf($player, $catalogue));
        self::assertCount(1, array_filter(self::messagesOn($this->imports), static fn (object $message): bool => $message instanceof ProcessUnlistedPairing));
        // A double tap answers the same pairing
        self::assertSame([UnlistedPairingOutcome::SAME_REQUEST, $outcome->pairingId], [$this->pairUnlisted($player, $unlisted['id'], $catalogue, $requestId, work: false)->status, $outcome->pairingId]);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing WHERE unlisted_puzzle_id = :id', ['id' => $unlisted['id']]));

        self::assertNotNull($outcome->pairingId);
        $this->workPairing($outcome->pairingId);

        // Two new results through the import's writer; the one the player had is that one; the similar one and the
        // month-only one stay unlisted, each with its reason - nothing is waved through
        $results = $this->resultsOf($player, $catalogue);
        self::assertSame(
            [['2024-01-02 00:00:00', 2400, true, 'Public words'], ['2024-01-05 00:00:00', null, true, null], ['2024-01-09 00:00:00', 2500, false, null], ['2024-01-12 00:00:00', 2700, false, null]],
            array_map(static fn (array $result): array => [$result['finished_at'], $result['seconds_to_solve'], $result['created_via'] === 'spreadsheet_import', $result['comment']], $results),
        );
        $byRow = [];

        foreach ($this->unlistedResults($unlisted['id']) as $result) {
            self::assertIsString($result['import_row_id']);
            $byRow[$result['import_row_id']] = $result;
        }

        self::assertSame([true, true, null], [$byRow[$new]['converted'], $byRow[$new]['converted_created'], $byRow[$new]['kept_reason']]);
        self::assertSame([true, true], [$byRow[$untimed]['converted'], $byRow[$untimed]['converted_created']]);
        self::assertSame([true, false, $typedEqual], [$byRow[$equal]['converted'], $byRow[$equal]['converted_created'], $byRow[$equal]['converted_solving_time_id']]);
        self::assertSame([false, 'similar_result_exists'], [$byRow[$similar]['converted'], $byRow[$similar]['kept_reason']]);
        self::assertSame([false, 'needs_exact_day'], [$byRow[$month]['converted'], $byRow[$month]['kept_reason']]);
        self::assertNotSame($typedSimilar, $byRow[$similar]['converted_solving_time_id']);

        // The rows of the file name what their results are now - the engine's "never twice" rests on that
        self::assertSame($byRow[$new]['converted_solving_time_id'], $this->rowNow($new)['solving_time_id']);
        self::assertSame(['profile', $typedEqual], [$this->rowNow($equal)['already_yours_how'], $this->rowNow($equal)['already_yours_solving_time_id']]);

        // What the player owns is in their collection, with the row's comment
        self::assertSame(
            [['puzzle_id' => $catalogue, 'comment' => 'Box a bit torn']],
            $this->pairingDatabase()->fetchAllAssociative('SELECT puzzle_id, comment FROM collection_item WHERE player_id = :id AND collection_id IS NULL', ['id' => $player->id->toString()]),
        );

        $pairing = $this->pairingNow($outcome->pairingId);
        self::assertSame(
            ['finished', 2, 1, 2, 1, 0, TestClock::FROZEN_NOW],
            [$pairing['state'], $pairing['results_added'], $pairing['results_already_yours'], $pairing['results_kept'], $pairing['items_added'], $pairing['items_already_yours'], $pairing['finished_at']],
        );
        self::assertSame($catalogue, $this->unlistedPuzzle($player, 'Zq Pair My Harbour Copy')['paired_puzzle_id']);
        // What is owed for the two new results - on the import's own queue
        self::assertCount(2, array_filter(self::messagesOn($this->imports), static fn (object $message): bool => $message instanceof AwardXpForSolvingTime));
        // The puzzle's statistics know them: two typed before, two from the pairing
        self::assertSame(4, $this->pairingCount('SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id', ['id' => $catalogue]));

        // The player's next file pairs directly: the remembered decision for the key is the catalogue puzzle
        self::assertSame(
            ['choice' => 'catalogue', 'puzzle_id' => $catalogue],
            $this->pairingDatabase()->fetchAssociative('SELECT choice, puzzle_id FROM import_puzzle_decision WHERE player_id = :id AND puzzle_key = :key', ['id' => $player->id->toString(), 'key' => ImportPuzzleKey::of('Zq Pair My Harbour Copy', 750, 'Zqpair')]),
        );

        // The wish mark of another unlisted puzzle becomes a wish list item
        $dream = $this->unlistedPuzzle($player, 'Zq Pair My Dream Copy');
        $this->pairUnlisted($player, $dream['id'], $wishTarget);
        self::assertSame([$wishTarget], $this->pairingDatabase()->fetchFirstColumn('SELECT puzzle_id FROM wish_list_item WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame('added', $this->rowNow($wish)['state']);

        // Trying the rest again adds nothing: the same two stay unlisted for the same reasons
        $rest = $this->pairUnlisted($player, $unlisted['id'], $catalogue);
        self::assertSame(UnlistedPairingOutcome::STARTED, $rest->status);
        $restNow = $this->pairingNow($rest->pairingId);
        self::assertSame([0, 0, 2], [$restNow['results_added'], $restNow['results_already_yours'], $restNow['results_kept']]);
        self::assertCount(4, $this->resultsOf($player, $catalogue));

        // An Undo of the import's "Add" leaves the rows whose results are real ones now where they are
        $this->undo($import, $player, DataImportUndoScope::Batch, $batch->id);
        self::assertSame('added', $this->rowNow($new)['state']);
        self::assertSame('added', $this->rowNow($owned)['state']);
        self::assertCount(4, $this->resultsOf($player, $catalogue));
        // … and takes back what is still unlisted
        self::assertSame('waiting', $this->rowNow($similar)['state']);
        self::assertSame('waiting', $this->rowNow($month)['state']);
        self::assertCount(3, $this->unlistedResults($unlisted['id']));

        // Undo of the pairing: the results it made are deleted - the typed ones stay -, the collection item goes, the
        // puzzle is unlisted again and the remembered decision with it
        $undo = $this->undoPairing($player, (string) $outcome->pairingId);
        self::assertSame(UnlistedPairingOutcome::STARTED, $undo->status);
        self::assertSame([$typedEqual, $typedSimilar], array_column($this->resultsOf($player, $catalogue), 'id'));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame([false, false, false], array_column($this->unlistedResults($unlisted['id']), 'converted'));
        self::assertSame([false], array_column($this->unlistedMarks($unlisted['id']), 'converted'));
        self::assertNull($this->unlistedPuzzle($player, 'Zq Pair My Harbour Copy')['paired_puzzle_id']);
        self::assertNull($this->rowNow($new)['solving_time_id']);
        $undone = $this->pairingNow($outcome->pairingId);
        self::assertSame(['undone', 2, 0], [$undone['state'], $undone['results_taken_back'], $undone['results_kept_by_undo']]);
        self::assertSame('unlisted', $this->pairingDatabase()->fetchOne('SELECT choice FROM import_puzzle_decision WHERE player_id = :id AND puzzle_key = :key', ['id' => $player->id->toString(), 'key' => ImportPuzzleKey::of('Zq Pair My Harbour Copy', 750, 'Zqpair')]));
        self::assertSame(2, $this->pairingCount("SELECT COUNT(*) FROM solving_time_graveyard WHERE deleted_via = 'import_undo' AND player_id = :id", ['id' => $player->id->toString()]));
        // Asked again, the Undo answers the same and does nothing
        self::assertSame(UnlistedPairingOutcome::SAME_REQUEST, $this->undoPairing($player, (string) $outcome->pairingId)->status);
    }

    public function testAResultChangedSinceThePairingIsKeptByItsUndo(): void
    {
        $player = $this->seedPairingPlayer('pairing-edited');
        $brand = $this->seedBrand('Zqedit');
        $catalogue = $this->seedCataloguePuzzle('Zq Edit Catalogue Orchard', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Edit My Orchard Copy', 500, 'Zqedit', '2024-04-01', 3000);
        $this->resultRow($import, $sheet, 'Zq Edit My Orchard Copy', 500, 'Zqedit', '2024-04-08', 3100);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Edit My Orchard Copy');
        $this->addAndWork($import, $player);
        $unlisted = $this->unlistedPuzzle($player, 'Zq Edit My Orchard Copy');

        $outcome = $this->pairUnlisted($player, $unlisted['id'], $catalogue);
        $made = $this->resultsOf($player, $catalogue);
        self::assertCount(2, $made);
        // The player corrects one of them on their profile
        $this->pairingDatabase()->update('puzzle_solving_time', ['seconds_to_solve' => 2999], ['id' => $made[0]['id']]);

        $this->undoPairing($player, (string) $outcome->pairingId);

        self::assertSame([$made[0]['id']], array_column($this->resultsOf($player, $catalogue), 'id'));
        $undone = $this->pairingNow($outcome->pairingId);
        self::assertSame(['undone', 1, 1], [$undone['state'], $undone['results_taken_back'], $undone['results_kept_by_undo']]);
        self::assertEqualsCanonicalizing(['edited_since', null], array_column($this->unlistedResults($unlisted['id']), 'kept_reason'));
        // Something of it is still a real result: the puzzle stays paired
        self::assertSame($catalogue, $this->unlistedPuzzle($player, 'Zq Edit My Orchard Copy')['paired_puzzle_id']);
    }

    public function testOnlyTheOwnerPairsAndOnlyWithAPuzzleTheyMayChoose(): void
    {
        $player = $this->seedPairingPlayer('pairing-rules');
        $other = $this->seedPairingPlayer('pairing-other');
        $brand = $this->seedBrand('Zqrules');
        $hidden = $this->seedCataloguePuzzle('Zq Rules Secret', 500, $brand, hideUntil: TestClock::now()->modify('+30 days'));
        $waiting = $this->seedCataloguePuzzle('Zq Rules Waiting For Approval', 500, $brand, approved: false);
        $open = $this->seedCataloguePuzzle('Zq Rules Open', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Rules My Copy', 500, 'Zqrules', '2024-05-01', 3000);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Rules My Copy');
        $this->addAndWork($import, $player);
        $unlisted = $this->unlistedPuzzle($player, 'Zq Rules My Copy');
        $before = $this->publicCounts();

        // A hidden puzzle and one that waits for approval are never a choice - whatever a form sends
        self::assertSame(UnlistedPairingOutcome::NOT_AVAILABLE, $this->pairUnlisted($player, $unlisted['id'], $hidden)->status);
        self::assertSame(UnlistedPairingOutcome::NOT_AVAILABLE, $this->pairUnlisted($player, $unlisted['id'], $waiting)->status);
        self::assertSame(UnlistedPairingOutcome::NOT_AVAILABLE, $this->pairUnlisted($player, $unlisted['id'], 'not-a-puzzle')->status);

        // Somebody else's unlisted puzzle is "not found" - nobody can tell it from one that does not exist
        try {
            $this->pairUnlisted($other, $unlisted['id'], $open);
            self::fail('Another player paired an unlisted puzzle that is not theirs.');
        } catch (UnlistedPuzzleNotFound) {
            $this->pairingEntityManager()->clear();
        }

        try {
            $this->handled(self::getContainer()->get(RemoveUnlistedPuzzleHandler::class), new RemoveUnlistedPuzzle($unlisted['id'], $other->id->toString()));
            self::fail('Another player removed an unlisted puzzle that is not theirs.');
        } catch (UnlistedPuzzleNotFound) {
        }

        // While adding is paused (the import's safety switch) nothing is paired
        $this->pairingDatabase()->executeStatement("INSERT INTO data_import_settings (id, adding_paused, adding_paused_reason, adding_paused_changed_at) VALUES (1, true, 'test', :now) ON CONFLICT (id) DO UPDATE SET adding_paused = true", ['now' => TestClock::FROZEN_NOW]);
        self::assertSame(UnlistedPairingOutcome::PAUSED, $this->pairUnlisted($player, $unlisted['id'], $open)->status);
        $this->pairingDatabase()->executeStatement('UPDATE data_import_settings SET adding_paused = false');

        self::assertSame($before, $this->publicCounts());
        self::assertNull($this->unlistedPuzzle($player, 'Zq Rules My Copy')['paired_puzzle_id']);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing'));

        // The owner removes it: the puzzle goes with its results
        $this->handled(self::getContainer()->get(RemoveUnlistedPuzzleHandler::class), new RemoveUnlistedPuzzle($unlisted['id'], $player->id->toString()));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_puzzle WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_result WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testTheDailyLookSuggestsACertainMatchTellsThePlayerOnceAndNeverPairsByItself(): void
    {
        $player = $this->seedPairingPlayer('suggested');
        $brand = $this->seedBrand('Zqsug');

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Sug Lighthouse At Dawn', 500, 'Zqsug', '2024-06-01', 3000);
        $this->resultRow($import, $sheet, 'Zq Sug Nothing Like It', 500, 'Zqsug', '2024-06-02', 3100);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Sug Lighthouse At Dawn', 'Zq Sug Nothing Like It');
        $this->addAndWork($import, $player);

        // The catalogue grows: the very puzzle - name, pieces and brand - is added
        $grown = $this->seedCataloguePuzzle('Zq Sug Lighthouse At Dawn', 500, $this->pairingEntityManager()->find(Manufacturer::class, $brand->id->toString()));
        $before = $this->publicCounts();
        $handler = self::getContainer()->get(SuggestUnlistedPuzzlePairingsHandler::class);
        $done = null;
        $this->handled(static function (SuggestUnlistedPuzzlePairings $message) use ($handler, &$done): void {
            $done = $handler($message);
        }, new SuggestUnlistedPuzzlePairings());

        self::assertIsArray($done);
        self::assertSame([1, 1], [$done['suggested'], $done['playersTold']]);
        self::assertGreaterThanOrEqual(2, $done['looked']);
        $lighthouse = $this->unlistedPuzzle($player, 'Zq Sug Lighthouse At Dawn');
        // Suggested - and nothing else: not paired, no result made, nothing public moved
        self::assertSame([$grown, null, null], [$lighthouse['suggested_puzzle_id'], $lighthouse['paired_puzzle_id'], $lighthouse['paired_at']]);
        self::assertNull($this->unlistedPuzzle($player, 'Zq Sug Nothing Like It')['suggested_puzzle_id']);
        self::assertSame($before, $this->publicCounts());
        self::assertSame([false], array_column($this->unlistedResults($lighthouse['id']), 'converted'));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing'));

        $told = fn (): int => $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id AND type = :type', ['id' => $player->id->toString(), 'type' => NotificationType::UnlistedPuzzlesFound->value]);
        self::assertSame(1, $told());

        // The notifications page reads it - it has no target, and names nothing of the puzzles
        $notifications = array_values(array_filter(
            self::getContainer()->get(GetNotifications::class)->forPlayer($player->id->toString(), 10, 0),
            static fn ($notification): bool => $notification->isUnlistedPuzzlesNotification(),
        ));
        self::assertCount(1, $notifications);

        // The next runs find the same and tell nothing again - on the same day, and when the puzzle is asked about again
        $this->handled($handler, new SuggestUnlistedPuzzlePairings());
        $this->pairingDatabase()->executeStatement('UPDATE unlisted_puzzle SET match_checked_at = NULL');
        $this->handled($handler, new SuggestUnlistedPuzzlePairings());
        self::assertSame(1, $told());

        // "Not this one": the suggestion goes and is never made again
        $this->handled(self::getContainer()->get(AnswerUnlistedSuggestionHandler::class), new AnswerUnlistedSuggestion($lighthouse['id'], $player->id->toString()));
        self::assertNull($this->unlistedPuzzle($player, 'Zq Sug Lighthouse At Dawn')['suggested_puzzle_id']);
        $this->pairingDatabase()->executeStatement('UPDATE unlisted_puzzle SET match_checked_at = NULL');
        $this->handled($handler, new SuggestUnlistedPuzzlePairings());
        self::assertNull($this->unlistedPuzzle($player, 'Zq Sug Lighthouse At Dawn')['suggested_puzzle_id']);
        self::assertSame(1, $told());
    }

    public function testAccountDeletionRemovesEverythingUnlisted(): void
    {
        $player = $this->seedPairingPlayer('unlisted-deleted');
        $brand = $this->seedBrand('Zqgone');
        $catalogue = $this->seedCataloguePuzzle('Zq Gone Catalogue', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Gone My Copy', 500, 'Zqgone', '2024-07-01', 3000);
        $this->monthRow($import, $sheet, 'Zq Gone My Copy', 500, 'Zqgone', '2020-02', 3100);
        $this->ownedRow($import, $sheet, 'Zq Gone My Copy', 500, 'Zqgone');
        $this->resultRow($import, $sheet, 'Zq Gone Another Copy', 300, 'Zqgone', '2024-07-02', 2000);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Gone My Copy', 'Zq Gone Another Copy');
        $this->addAndWork($import, $player);
        $this->pairUnlisted($player, $this->unlistedPuzzle($player, 'Zq Gone My Copy')['id'], $catalogue);
        $playerId = $player->id->toString();

        foreach (['unlisted_puzzle' => 2, 'unlisted_result' => 3, 'unlisted_ownership' => 1, 'unlisted_pairing' => 1] as $table => $rows) {
            self::assertSame($rows, $this->pairingCount("SELECT COUNT(*) FROM {$table} WHERE player_id = :id", ['id' => $playerId]), $table);
        }

        $this->pairingEntityManager()->clear();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeletePlayer($playerId));
        $this->pairingEntityManager()->clear();

        foreach (['unlisted_puzzle', 'unlisted_result', 'unlisted_ownership', 'unlisted_pairing'] as $table) {
            self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM {$table} WHERE player_id = :id", ['id' => $playerId]), $table);
        }

        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM player WHERE id = :id', ['id' => $playerId]));
    }

    /**
     * A row with a result known to the month only, keyed as the parser keys it.
     */
    private function monthRow(DataImport $import, DataImportSheet $sheet, string $name, int $pieces, string $brand, string $month, int $seconds): string
    {
        [$import, $sheet] = $this->managed($import, $sheet);
        $puzzleKey = ImportPuzzleKey::of($name, $pieces, $brand);
        self::assertNotNull($puzzleKey);
        $key = ImportSourceKey::result($puzzleKey, new DateTimeImmutable($month . '-01'), DatePrecision::Month, $seconds, PuzzlingType::Solo, 1);

        return $this->seedRow($import, $sheet, $name, $pieces, $brand, [
            'date' => $month,
            'datePrecision' => 'month',
            'seconds' => $seconds,
            'type' => 'solo',
            'keys' => ['result' => $key],
        ], DataImportRowKind::Result, $key);
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
}
