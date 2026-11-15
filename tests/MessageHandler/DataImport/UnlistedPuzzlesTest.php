<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UnlistedPairing;
use SpeedPuzzling\Web\Entity\UnlistedPuzzle;
use SpeedPuzzling\Web\Entity\UnlistedResult;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetUnlistedPuzzles;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\DataImportLimits;
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
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
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
        // A result without any date has nothing to be written with: it stays unlisted
        $month = $this->undatedRow($import, $sheet, 'Zq Pair My Harbour Copy', 750, 'Zqpair', 2300);
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
        // undated one stay unlisted, each with its reason - nothing is waved through
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

    public function testAnUnlistedResultThatKnowsOnlyItsMonthBecomesAResultOfThatPrecisionWhenPaired(): void
    {
        $player = $this->seedPairingPlayer('pairing-month');
        $brand = $this->seedBrand('Zqmonth');
        $catalogue = $this->seedCataloguePuzzle('Zq Month Catalogue', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        // Sheet order: the month first, then the very day of it with the same time, then a year, then nothing
        $month = $this->monthRow($import, $sheet, 'Zq Month My Copy', 500, 'Zqmonth', '2021-09', 3000);
        $day = $this->resultRow($import, $sheet, 'Zq Month My Copy', 500, 'Zqmonth', '2021-09-14', 3000);
        $otherMonth = $this->monthRow($import, $sheet, 'Zq Month My Copy', 500, 'Zqmonth', '2022-03', 3100);
        $year = $this->resultRow($import, $sheet, 'Zq Month My Copy', 500, 'Zqmonth', '2019', 3200);
        $undated = $this->undatedRow($import, $sheet, 'Zq Month My Copy', 500, 'Zqmonth', 3300);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Month My Copy');
        $this->addAndWork($import, $player);
        $unlisted = $this->unlistedPuzzle($player, 'Zq Month My Copy');
        self::assertCount(5, $this->unlistedResults($unlisted['id']));

        $outcome = $this->pairUnlisted($player, $unlisted['id'], $catalogue);

        // Stored as the file knows them (date-precision.md): the first day of the period, with its precision
        self::assertSame(
            [['2019-01-01 00:00:00', 'year', 3200], ['2021-09-14 00:00:00', null, 3000], ['2022-03-01 00:00:00', 'month', 3100]],
            array_map(
                static fn (array $result): array => [$result['finished_at'], $result['finished_at_precision'], $result['seconds_to_solve']],
                $this->pairingDatabase()->fetchAllAssociative('SELECT finished_at, finished_at_precision, seconds_to_solve FROM puzzle_solving_time WHERE player_id = :id AND puzzle_id = :puzzle ORDER BY finished_at', ['id' => $player->id->toString(), 'puzzle' => $catalogue]),
            ),
        );
        $byRow = [];

        foreach ($this->unlistedResults($unlisted['id']) as $result) {
            self::assertIsString($result['import_row_id']);
            $byRow[$result['import_row_id']] = $result;
        }

        // "Sept 2021" is the result its day made - whatever their order in the file -, never a second one
        self::assertSame([true, false, $byRow[$day]['converted_solving_time_id']], [$byRow[$month]['converted'], $byRow[$month]['converted_created'], $byRow[$month]['converted_solving_time_id']]);
        self::assertSame([true, true], [$byRow[$day]['converted'], $byRow[$day]['converted_created']]);
        self::assertSame([true, true], [$byRow[$otherMonth]['converted_created'], $byRow[$year]['converted_created']]);
        // Only the one without any date stays unlisted
        self::assertSame([false, 'needs_exact_day'], [$byRow[$undated]['converted'], $byRow[$undated]['kept_reason']]);
        $pairing = $this->pairingNow($outcome->pairingId);
        self::assertSame([3, 1, 1], [$pairing['results_added'], $pairing['results_already_yours'], $pairing['results_kept']]);
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

    public function testWhatAPairingMakesPublicCountsInTheDailyGuardAndTheRestWaitsForTomorrow(): void
    {
        $player = $this->seedPairingPlayer('pairing-guard');
        $brand = $this->seedBrand('Zqguard');
        $catalogue = $this->seedCataloguePuzzle('Zq Guard Catalogue', 500, $brand);
        $playerId = $player->id->toString();
        $rows = self::getContainer()->get(DataImportRowRepository::class);
        $startOfDay = TestClock::now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        [$import, $sheet] = $this->importOf($player);
        $first = $this->resultRow($import, $sheet, 'Zq Guard My Copy', 500, 'Zqguard', '2024-08-01', 3000);
        $this->resultRow($import, $sheet, 'Zq Guard My Copy', 500, 'Zqguard', '2024-08-08', 3100);
        $this->resultRow($import, $sheet, 'Zq Guard My Copy', 500, 'Zqguard', '2024-08-15', 3200);
        $this->ownedRow($import, $sheet, 'Zq Guard My Copy', 500, 'Zqguard');
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Guard My Copy');

        // The day is used up by results the player's imports made public today (all but none are left) …
        $this->pairingDatabase()->executeStatement(
            <<<SQL
INSERT INTO data_import_row (id, import_id, sheet_id, row_number, first_seen_version, last_seen_version, add_as_separate, state, result_made, kind, solving_time_id, result_made_at, added_at)
SELECT gen_random_uuid(), :importId, :sheetId, 100000 + filler, 1, 1, FALSE, 'added', TRUE, 'result', gen_random_uuid(), :now, :now
FROM generate_series(1, :limit) filler
SQL,
            ['importId' => $import->id->toString(), 'sheetId' => $sheet->id->toString(), 'now' => TestClock::FROZEN_NOW, 'limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY],
        );
        self::assertSame(DataImportLimits::MAX_RESULTS_ADDED_PER_DAY, $rows->countResultsAddedOrQueuedSince($playerId, $startOfDay));

        // … and rows kept as unlisted are added all the same: they are private, the guard is about what is public
        $added = $this->addAndWork($import, $player);
        self::assertSame([AddImportRowsOutcome::STARTED, 4, []], [$added->status, $added->rows, $added->skipped]);
        self::assertSame(DataImportLimits::MAX_RESULTS_ADDED_PER_DAY, $rows->countResultsAddedOrQueuedSince($playerId, $startOfDay));
        $unlisted = $this->unlistedPuzzle($player, 'Zq Guard My Copy');
        self::assertCount(3, $this->unlistedResults($unlisted['id']));

        // The rows were added long ago; two results are left of today
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET added_at = '2020-01-01 00:00:00' WHERE import_id = :id AND row_number < 100000", ['id' => $import->id->toString()]);
        $this->pairingDatabase()->executeStatement('DELETE FROM data_import_row WHERE import_id = :id AND row_number IN (100001, 100002)', ['id' => $import->id->toString()]);

        $outcome = $this->pairUnlisted($player, $unlisted['id'], $catalogue);

        // Two results became public - and count as made today, whenever their rows were added; the third waits. The
        // mark is not held: only results use the day up
        self::assertCount(2, $this->resultsOf($player, $catalogue));
        self::assertSame(DataImportLimits::MAX_RESULTS_ADDED_PER_DAY, $rows->countResultsAddedOrQueuedSince($playerId, $startOfDay));
        self::assertSame(TestClock::FROZEN_NOW, $this->pairingDatabase()->fetchOne('SELECT result_made_at FROM data_import_row WHERE id = :id', ['id' => $first]));
        $waiting = $this->pairingNow($outcome->pairingId);
        self::assertSame(['running', TestClock::FROZEN_NOW], [$waiting['state'], $waiting['daily_limit_reached_at']]);
        self::assertSame([true, true, false], array_column($this->unlistedResults($unlisted['id']), 'converted'));
        self::assertSame([null, null, null], array_column($this->unlistedResults($unlisted['id']), 'kept_reason'));
        self::assertSame([true], array_column($this->unlistedMarks($unlisted['id']), 'converted'));
        // Asked again the same day, it still waits - nothing is added over the guard
        $this->workPairing((string) $outcome->pairingId, 3);
        self::assertCount(2, $this->resultsOf($player, $catalogue));

        // The owner's page says so
        self::assertTrue(self::getContainer()->get(GetUnlistedPuzzles::class)->one($playerId, $unlisted['id'])['pairings'][0]['waitsForTomorrow']);

        // Tomorrow (nothing was made public "today" any more) the sweeper's turn goes on and finishes it
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET result_made_at = '2020-01-01 00:00:00', added_at = '2020-01-01 00:00:00' WHERE import_id = :id", ['id' => $import->id->toString()]);
        $this->workPairing((string) $outcome->pairingId);
        $done = $this->pairingNow($outcome->pairingId);
        self::assertSame(['finished', 3, 0, null], [$done['state'], $done['results_added'], $done['results_kept'], $done['daily_limit_reached_at']]);
        self::assertCount(3, $this->resultsOf($player, $catalogue));

        // An Undo gives the day back: a result that is gone is not counted
        self::assertSame(1, $rows->countResultsAddedOrQueuedSince($playerId, $startOfDay));
        $this->undoPairing($player, (string) $outcome->pairingId);
        self::assertSame(0, $rows->countResultsAddedOrQueuedSince($playerId, $startOfDay));
    }

    public function testAPuzzleMergedBetweenTheTapAndTheStepTakesTheResultsOnTheSurvivor(): void
    {
        $player = $this->seedPairingPlayer('pairing-merged');
        $brand = $this->seedBrand('Zqumerge');
        $survivor = $this->seedCataloguePuzzle('Zq Umerge Survivor', 500, $brand);
        $duplicate = $this->seedCataloguePuzzle('Zq Umerge Duplicate', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Umerge My Copy', 500, 'Zqumerge', '2024-09-01', 3000);
        $this->ownedRow($import, $sheet, 'Zq Umerge My Copy', 500, 'Zqumerge');
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Umerge My Copy');
        $this->addAndWork($import, $player);
        $unlisted = $this->unlistedPuzzle($player, 'Zq Umerge My Copy');

        // The tap - and before the worker comes to it, the catalogue puzzle is merged into another
        $outcome = $this->pairUnlisted($player, $unlisted['id'], $duplicate, work: false);
        self::assertSame(UnlistedPairingOutcome::STARTED, $outcome->status);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $mergeRequestId = Uuid::uuid7()->toString();
        $bus->dispatch(new SubmitPuzzleMergeRequest(mergeRequestId: $mergeRequestId, sourcePuzzleId: $survivor, reporterId: PlayerFixture::PLAYER_REGULAR, duplicatePuzzleIds: [$duplicate]));
        $bus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: $survivor,
            mergedName: 'Zq Umerge Survivor',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: $brand->id->toString(),
            selectedImagePuzzleId: null,
        ));
        $this->pairingEntityManager()->clear();

        $this->workPairing((string) $outcome->pairingId);

        // Nothing is left "not available": the result and the collection item are on the survivor
        self::assertSame([$survivor], array_column($this->resultsOf($player), 'puzzle_id'));
        self::assertSame([null], array_column($this->unlistedResults($unlisted['id']), 'kept_reason'));
        self::assertSame([$survivor], $this->pairingDatabase()->fetchFirstColumn('SELECT puzzle_id FROM collection_item WHERE player_id = :id', ['id' => $player->id->toString()]));
        $done = $this->pairingNow($outcome->pairingId);
        self::assertSame(['finished', 1, 0, 1], [$done['state'], $done['results_added'], $done['results_kept'], $done['items_added']]);

        // … and the Undo takes them back from there (a merge is not a change of the player's)
        $this->undoPairing($player, (string) $outcome->pairingId);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame('undone', $this->pairingNow($outcome->pairingId)['state']);
    }

    public function testPairingAndRemovingTakeThePlayersImportLockSoTwoTabsNeverMeetHalfWay(): void
    {
        $brand = $this->seedBrand('Zqlock');
        $catalogue = $this->seedCataloguePuzzle('Zq Lock Catalogue', 500, $brand);
        $entityManager = $this->pairingEntityManager();
        $held = fn (string $playerId): int => $this->pairingCount(
            "SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND objsubid = 1 AND ((classid::bigint << 32) | objid::bigint) = hashtextextended(:key, 0)",
            ['key' => 'import-result:' . $playerId],
        );

        foreach (['pair', 'undo', 'remove'] as $action) {
            // An unlisted puzzle written directly - nothing of the engine has taken this player's lock yet
            $player = $this->seedPairingPlayer('lock-' . $action);
            $player = $entityManager->find(Player::class, $player->id->toString());
            self::assertNotNull($player);
            $unlistedPuzzle = new UnlistedPuzzle(Uuid::uuid7(), $player, '1:' . hash('sha256', 'lock-' . $action), 'Zq Lock My Copy', TestClock::now());
            $entityManager->persist($unlistedPuzzle);
            $entityManager->persist(new UnlistedResult(Uuid::uuid7(), $unlistedPuzzle, $player, 3000, '2024-01-01', 'day', PuzzlingType::Solo, [], null, null, Uuid::uuid7(), '1:' . hash('sha256', 'lock-row-' . $action), 1, TestClock::now()));
            $pairing = new UnlistedPairing(Uuid::uuid7(), Uuid::uuid7(), $player, $unlistedPuzzle, Uuid::fromString($catalogue), TestClock::now());
            $pairing->finished(['resultsAdded' => 0, 'resultsAlreadyYours' => 0, 'resultsKept' => 1, 'itemsAdded' => 0, 'itemsAlreadyYours' => 0], TestClock::now());

            if ($action === 'undo') {
                $entityManager->persist($pairing);
            }

            $entityManager->flush();
            $playerId = $player->id->toString();
            $unlistedId = $unlistedPuzzle->id->toString();
            $pairingId = $pairing->id->toString();
            $entityManager->clear();
            self::assertSame(0, $held($playerId), $action);

            match ($action) {
                'pair' => $this->pairUnlisted($playerId, $unlistedId, $catalogue, work: false),
                'undo' => $this->undoPairing($playerId, $pairingId, work: false),
                default => self::getContainer()->get(MessageBusInterface::class)->dispatch(new RemoveUnlistedPuzzle($unlistedId, $playerId)),
            };

            // The very lock every step of a pairing and every row of an "Add" take (ImportWriteLock) - held until
            // the request's transaction ends
            self::assertSame(1, $held($playerId), $action);
        }
    }

    public function testAPairingTakenBackIsNotSuggestedAgainAndItsRecordOutlivesThePuzzle(): void
    {
        $player = $this->seedPairingPlayer('pairing-record');
        $brand = $this->seedBrand('Zqrec');

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Rec Lighthouse At Dusk', 500, 'Zqrec', '2024-10-01', 3000);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Rec Lighthouse At Dusk');
        $this->addAndWork($import, $player);
        $unlisted = $this->unlistedPuzzle($player, 'Zq Rec Lighthouse At Dusk');

        // The catalogue grows by the very puzzle; the player pairs by hand - and takes it back
        $grown = $this->seedCataloguePuzzle('Zq Rec Lighthouse At Dusk', 500, $this->pairingEntityManager()->find(Manufacturer::class, $brand->id->toString()));
        $outcome = $this->pairUnlisted($player, $unlisted['id'], $grown);
        self::assertCount(1, $this->resultsOf($player, $grown));
        $this->undoPairing($player, (string) $outcome->pairingId);
        self::assertSame([], $this->resultsOf($player, $grown));

        // The daily look would find it "certainly" - and says nothing: the player just said "not this puzzle"
        $handler = self::getContainer()->get(SuggestUnlistedPuzzlePairingsHandler::class);
        $this->handled($handler, new SuggestUnlistedPuzzlePairings());
        self::assertNull($this->unlistedPuzzle($player, 'Zq Rec Lighthouse At Dusk')['suggested_puzzle_id']);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id AND type = :type', ['id' => $player->id->toString(), 'type' => NotificationType::UnlistedPuzzlesFound->value]));
        // Pairing by hand stays open
        $again = $this->pairUnlisted($player, $unlisted['id'], $grown);
        self::assertSame(UnlistedPairingOutcome::STARTED, $again->status);
        $this->undoPairing($player, (string) $again->pairingId);

        // "Remove" takes the unlisted puzzle and its results - the records of its pairings stay: who, when, with
        // which catalogue puzzle, what came of it
        $this->handled(self::getContainer()->get(RemoveUnlistedPuzzleHandler::class), new RemoveUnlistedPuzzle($unlisted['id'], $player->id->toString()));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_puzzle WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_result WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(
            [
                ['state' => 'undone', 'unlisted_puzzle_id' => null, 'puzzle_id' => $grown, 'unlisted_puzzle_key' => ImportPuzzleKey::of('Zq Rec Lighthouse At Dusk', 500, 'Zqrec'), 'results_added' => 1, 'results_taken_back' => 1],
                ['state' => 'undone', 'unlisted_puzzle_id' => null, 'puzzle_id' => $grown, 'unlisted_puzzle_key' => ImportPuzzleKey::of('Zq Rec Lighthouse At Dusk', 500, 'Zqrec'), 'results_added' => 1, 'results_taken_back' => 1],
            ],
            $this->pairingDatabase()->fetchAllAssociative('SELECT state, unlisted_puzzle_id, puzzle_id, unlisted_puzzle_key, results_added, results_taken_back FROM unlisted_pairing WHERE player_id = :id ORDER BY id', ['id' => $player->id->toString()]),
        );
        // … and what was public for a while is whole in the graveyard
        self::assertSame(2, $this->pairingCount("SELECT COUNT(*) FROM solving_time_graveyard WHERE deleted_via = 'import_undo' AND player_id = :id", ['id' => $player->id->toString()]));
        // A record without its unlisted puzzle is nothing to undo
        $this->expectException(UnlistedPuzzleNotFound::class);
        $this->undoPairing($player, (string) $outcome->pairingId);
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
        return $this->resultRow($import, $sheet, $name, $pieces, $brand, $month, $seconds);
    }

    /**
     * A row with a result that has no date at all.
     */
    private function undatedRow(DataImport $import, DataImportSheet $sheet, string $name, int $pieces, string $brand, int $seconds): string
    {
        [$import, $sheet] = $this->managed($import, $sheet);

        return $this->seedRow($import, $sheet, $name, $pieces, $brand, ['seconds' => $seconds, 'type' => 'solo']);
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
