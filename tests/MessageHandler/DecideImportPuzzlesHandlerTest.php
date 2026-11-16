<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Message\RequestImportPairing;
use SpeedPuzzling\Web\MessageHandler\DecideImportPuzzlesHandler;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportPairingScenario;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The player's decisions about the puzzles of their import (docs/features/data-import/pairing.md "Decisions"):
 * stored as theirs, remembered for the next file, kept by every later run of the pairing - and refused whole when
 * the page is stale, the import is somebody else's or the puzzle may not be chosen.
 */
final class DecideImportPuzzlesHandlerTest extends KernelTestCase
{
    use ImportPairingScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testADecisionIsRememberedInThePlayersNextImport(): void
    {
        $player = $this->seedPairingPlayer('remember');
        $other = $this->seedPairingPlayer('other');
        $puzzle = $this->seedCataloguePuzzle('Zq Remembered Harbour', 1000, $this->seedBrand('Zqremember'));

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Remembered Harbour', 1000);
        $this->seedRow($import, $sheet, 'Zq Nothing Like It Anywhere', 300);
        // The player's next file, and somebody else's with the same puzzle - read later
        [$next, $nextSheet] = $this->seedImport($player);
        $this->seedRow($next, $nextSheet, 'zq remembered  HARBOUR', 1000);
        $this->seedRow($next, $nextSheet, 'Zq Nothing Like It Anywhere', 300);
        [$theirs, $theirSheet] = $this->seedImport($other);
        $this->seedRow($theirs, $theirSheet, 'Zq Remembered Harbour', 1000);
        $this->pair($import);

        $probably = $this->importPuzzle($import, 'Zq Remembered Harbour');
        self::assertSame(['probably', 'undecided'], [$probably['tier'], $probably['choice']]);

        $this->decide($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($probably['id'], ImportPuzzleChoice::Catalogue, $puzzle, 0),
            new ImportPuzzleDecisionChange($this->importPuzzle($import, 'Zq Nothing Like It Anywhere')['id'], ImportPuzzleChoice::Unlisted, null, 0),
        ]);

        $decided = $this->importPuzzle($import, 'Zq Remembered Harbour');
        self::assertSame(['probably', 'catalogue', $puzzle, 'player'], [$decided['tier'], $decided['choice'], $decided['puzzle_id'], $decided['decided_how']]);
        self::assertSame(TestClock::FROZEN_NOW, $decided['decided_at']);
        self::assertSame(
            [['choice' => 'catalogue', 'puzzle_id' => $puzzle], ['choice' => 'unlisted', 'puzzle_id' => null]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT choice, puzzle_id FROM import_puzzle_decision WHERE player_id = :id ORDER BY choice', ['id' => $player->id->toString()]),
        );

        // The same puzzles in the next file arrive decided - in somebody else's they do not
        $this->pair($next);
        $this->pair($theirs);

        $remembered = $this->importPuzzle($next, 'zq remembered  HARBOUR');
        self::assertSame(['catalogue', $puzzle, 'remembered', TestClock::FROZEN_NOW], [$remembered['choice'], $remembered['puzzle_id'], $remembered['decided_how'], $remembered['decided_at']]);
        self::assertSame(['unlisted', 'remembered'], [$this->importPuzzle($next, 'Zq Nothing Like It Anywhere')['choice'], $this->importPuzzle($next, 'Zq Nothing Like It Anywhere')['decided_how']]);
        self::assertSame(['probably', 'undecided', null], [$this->importPuzzle($theirs, 'Zq Remembered Harbour')['tier'], $this->importPuzzle($theirs, 'Zq Remembered Harbour')['choice'], $this->importPuzzle($theirs, 'Zq Remembered Harbour')['decided_how']]);

        // It arrived decided: the rules never looked at it - it is in no pile of its own, only "Matched like last time"
        self::assertNull($remembered['tier']);
        $screen = self::getContainer()->get(GetImportPuzzleScreen::class);
        $nextId = $next->id->toString();
        self::assertSame([2, 0], [$screen->overview($nextId, $player->id->toString())->groups['remembered']['total'], $screen->overview($nextId, $player->id->toString())->searching]);

        // Taken back: it is nobody's decision any more - the pairing is due, so the import says "not paired" and
        // its run is asked for (a puzzle marked as the player's would be skipped by every run and wait for ever)
        $this->decide($next, $player->id->toString(), [
            new ImportPuzzleDecisionChange($remembered['id'], ImportPuzzleChoice::Undecided, null, $remembered['decision_version']),
        ]);
        $between = $this->importPuzzle($next, 'zq remembered  HARBOUR');
        self::assertSame(['undecided', null, null, null, $remembered['decision_version'] + 1], [$between['choice'], $between['puzzle_id'], $between['decided_how'], $between['tier'], $between['decision_version']]);
        self::assertNull($this->pairingDatabase()->fetchOne('SELECT paired_at FROM data_import WHERE id = :id', ['id' => $nextId]));
        self::assertSame(1, $screen->overview($nextId, $player->id->toString())->searching);

        // The run: the puzzle is in the pile its own look-up gives - undecided, no run ticks it again - and nothing
        // is "still looked for"
        $this->pair($next);

        $takenBack = $this->importPuzzle($next, 'zq remembered  HARBOUR');
        self::assertSame(['undecided', null, null], [$takenBack['choice'], $takenBack['puzzle_id'], $takenBack['decided_how']]);
        self::assertContains($takenBack['tier'], ['probably', 'choose']);
        $overview = $screen->overview($nextId, $player->id->toString());
        self::assertSame([0, 1, 1], [$overview->searching, $overview->groups['remembered']['total'], $overview->groups['probably']['open'] + $overview->groups['choose']['open']]);
        self::assertNotNull($this->pairingDatabase()->fetchOne('SELECT paired_at FROM data_import WHERE id = :id', ['id' => $nextId]));
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND tier IS NULL AND choice = 'undecided'", ['id' => $nextId]));
        // ... and what is remembered now is "not this puzzle"
        self::assertSame(
            [['choice' => 'not_this_puzzle', 'puzzle_id' => $puzzle], ['choice' => 'unlisted', 'puzzle_id' => null]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT choice, puzzle_id FROM import_puzzle_decision WHERE player_id = :id ORDER BY choice', ['id' => $player->id->toString()]),
        );

        // Taking back what names no puzzle is simply forgotten
        $unlisted = $this->importPuzzle($next, 'Zq Nothing Like It Anywhere');
        $this->decide($next, $player->id->toString(), [new ImportPuzzleDecisionChange($unlisted['id'], ImportPuzzleChoice::Undecided, null, $unlisted['decision_version'])]);
        self::assertSame(['not_this_puzzle'], $this->pairingDatabase()->fetchFirstColumn('SELECT choice FROM import_puzzle_decision WHERE player_id = :id', ['id' => $player->id->toString()]));
        // ... and that one too goes to its own pile with the next run: "Not in our database"
        $this->pair($next);
        // (asked anew: the numbers moved)
        $screen = self::getContainer()->get(GetImportPuzzleScreen::class);
        self::assertSame(['none', 'undecided', null], [$this->importPuzzle($next, 'Zq Nothing Like It Anywhere')['tier'], $this->importPuzzle($next, 'Zq Nothing Like It Anywhere')['choice'], $this->importPuzzle($next, 'Zq Nothing Like It Anywhere')['decided_how']]);
        self::assertSame([0, 0, 1], [$screen->overview($nextId, $player->id->toString())->searching, $screen->overview($nextId, $player->id->toString())->groups['remembered']['total'], $screen->overview($nextId, $player->id->toString())->groups['not-found']['open']]);

        // A puzzle left the old way (undecided, never looked at, marked as the player's - no run would take it, the
        // import "paired"): the safety net asks for a run although the last one came to rest, and the run takes it
        $this->pairingDatabase()->executeStatement("UPDATE data_import_puzzle SET tier = NULL, searched_at = NULL, candidates = '[]', decided_how = 'player', decided_at = NOW() WHERE id = :id", ['id' => $unlisted['id']]);
        self::assertSame(1, $screen->overview($nextId, $player->id->toString())->searching);
        self::assertTrue($this->handledWithResult(new RequestImportPairing($nextId)));
        $this->pair($next);
        $screen = self::getContainer()->get(GetImportPuzzleScreen::class);
        self::assertSame(['none', null], [$this->importPuzzle($next, 'Zq Nothing Like It Anywhere')['tier'], $this->importPuzzle($next, 'Zq Nothing Like It Anywhere')['decided_how']]);
        self::assertSame(0, $screen->overview($nextId, $player->id->toString())->searching);
        // ... and an import with nothing waiting is not asked about
        self::assertFalse($this->handledWithResult(new RequestImportPairing($nextId)));
        // Nor one whose finished run left a puzzle without a pile (another run would leave it again): the asking
        // is bounded by what a run changes - a finished run resets the count of tries, so that could not bound it
        $this->pairingDatabase()->executeStatement("UPDATE data_import_puzzle SET tier = NULL, searched_at = NOW(), decided_how = NULL, decided_at = (SELECT paired_at - INTERVAL '1 minute' FROM data_import WHERE data_import.id = data_import_puzzle.import_id) WHERE id = :id", ['id' => $unlisted['id']]);
        self::assertSame(1, $screen->overview($nextId, $player->id->toString())->searching);
        self::assertFalse($this->handledWithResult(new RequestImportPairing($nextId)));
    }

    /**
     * "The puzzle waits for you again" is a promise: a puzzle whose answer the player took back is never matched by
     * the rules - an Add would put its results on a public catalogue puzzle the player once kept for themselves,
     * without having been asked. An exact hit is "Looks like a match" at most.
     */
    public function testAPuzzleWhoseAnswerWasTakenBackIsNeverMatchedByTheRules(): void
    {
        $player = $this->seedPairingPlayer('takenbackexact');
        $playerId = $player->id->toString();
        $brand = $this->seedBrand('Zqtakenback');

        // An earlier file: neither puzzle is in the database - the player keeps one for themselves, leaves one out
        [$earlier, $earlierSheet] = $this->seedImport($player);
        $this->seedRow($earlier, $earlierSheet, 'Zq Taken Back Bay', 1000, 'Zqtakenback');
        $this->seedRow($earlier, $earlierSheet, 'Zq Taken Back Cove', 500, 'Zqtakenback');
        // The player's next file with the same two puzzles, and somebody else's with one of them - read later
        [$next, $nextSheet] = $this->seedImport($player);
        $this->seedRow($next, $nextSheet, 'Zq Taken Back Bay', 1000, 'Zqtakenback');
        $this->seedRow($next, $nextSheet, 'Zq Taken Back Cove', 500, 'Zqtakenback');
        $other = $this->seedPairingPlayer('takenbackexactother');
        [$theirs, $theirSheet] = $this->seedImport($other);
        $this->seedRow($theirs, $theirSheet, 'Zq Taken Back Bay', 1000, 'Zqtakenback');
        $this->pair($earlier);
        $this->decide($earlier, $playerId, [
            new ImportPuzzleDecisionChange($this->importPuzzle($earlier, 'Zq Taken Back Bay')['id'], ImportPuzzleChoice::Unlisted, null, 0),
            new ImportPuzzleDecisionChange($this->importPuzzle($earlier, 'Zq Taken Back Cove')['id'], ImportPuzzleChoice::LeftOut, null, 0),
        ]);

        // Meanwhile both are in the database, by their very name, brand and pieces - an exact hit
        $bay = $this->seedCataloguePuzzle('Zq Taken Back Bay', 1000, $this->pairingEntityManager()->getReference(Manufacturer::class, $brand->id));
        $cove = $this->seedCataloguePuzzle('Zq Taken Back Cove', 500, $this->pairingEntityManager()->getReference(Manufacturer::class, $brand->id));

        // The next file: both arrive as the player said ("Matched like last time") - and a third file's puzzle of
        // the same kind is matched by the rules, as ever: the hit IS exact
        $this->pair($next);
        self::assertSame(['unlisted', 'remembered', null], [$this->importPuzzle($next, 'Zq Taken Back Bay')['choice'], $this->importPuzzle($next, 'Zq Taken Back Bay')['decided_how'], $this->importPuzzle($next, 'Zq Taken Back Bay')['tier']]);
        $this->pair($theirs);
        self::assertSame(['found', 'catalogue', $bay, 'automatic'], [$this->importPuzzle($theirs, 'Zq Taken Back Bay')['tier'], $this->importPuzzle($theirs, 'Zq Taken Back Bay')['choice'], $this->importPuzzle($theirs, 'Zq Taken Back Bay')['puzzle_id'], $this->importPuzzle($theirs, 'Zq Taken Back Bay')['decided_how']]);

        // "Keep it just for me", unticked: the run finds the exact hit - and does NOT match it. "Looks like a
        // match", undecided, the hit as what it could be: the player says yes
        $kept = $this->importPuzzle($next, 'Zq Taken Back Bay');
        $this->decide($next, $playerId, [new ImportPuzzleDecisionChange($kept['id'], ImportPuzzleChoice::Undecided, null, $kept['decision_version'])]);
        $this->pair($next);
        $waits = $this->importPuzzle($next, 'Zq Taken Back Bay');
        self::assertSame(['probably', 'undecided', null, null], [$waits['tier'], $waits['choice'], $waits['puzzle_id'], $waits['decided_how']]);
        self::assertSame($bay, $waits['candidates'][0]['puzzleId']);
        // ... whatever runs later (rows read again), and whatever another file of the player remembers by then
        $this->pair($next);
        $this->pair($next);
        self::assertSame(['probably', 'undecided', null], [$this->importPuzzle($next, 'Zq Taken Back Bay')['tier'], $this->importPuzzle($next, 'Zq Taken Back Bay')['choice'], $this->importPuzzle($next, 'Zq Taken Back Bay')['decided_how']]);

        // A puzzle left the old way (undecided, never looked at, marked as the player's) and released by the run:
        // the same - never matched by the rules
        $this->pairingDatabase()->executeStatement("UPDATE data_import_puzzle SET choice = 'undecided', puzzle_id = NULL, decided_how = 'player', decided_at = NOW() WHERE import_id = :id AND name = 'Zq Taken Back Cove'", ['id' => $next->id->toString()]);
        $this->pairingDatabase()->executeStatement("DELETE FROM import_puzzle_decision WHERE player_id = :id AND choice = 'left_out'", ['id' => $playerId]);
        $this->pair($next);
        $released = $this->importPuzzle($next, 'Zq Taken Back Cove');
        self::assertSame(['probably', 'undecided', null, null], [$released['tier'], $released['choice'], $released['puzzle_id'], $released['decided_how']]);
        self::assertSame($cove, $released['candidates'][0]['puzzleId']);

        // The player's yes takes it - and it is theirs
        $this->decide($next, $playerId, [new ImportPuzzleDecisionChange($waits['id'], ImportPuzzleChoice::Catalogue, $bay, $this->importPuzzle($next, 'Zq Taken Back Bay')['decision_version'])]);
        $this->pair($next);
        self::assertSame(['catalogue', $bay, 'player'], [$this->importPuzzle($next, 'Zq Taken Back Bay')['choice'], $this->importPuzzle($next, 'Zq Taken Back Bay')['puzzle_id'], $this->importPuzzle($next, 'Zq Taken Back Bay')['decided_how']]);
    }

    public function testAPairingTakenBackIsNotMadeAgainWhenThePuzzleIsBuiltAnew(): void
    {
        $player = $this->seedPairingPlayer('taken-back');
        $brand = $this->seedBrand('Zqtakenback');
        $wrong = $this->seedCataloguePuzzle('Zq Same Name Other Edition', 500, $brand);

        [$import, $sheet] = $this->seedImport($player);
        $row = $this->seedRow($import, $sheet, 'Zq Same Name Other Edition', 500, 'Zqtakenback');
        [$nextFile, $nextSheet] = $this->seedImport($player);
        $this->seedRow($nextFile, $nextSheet, 'Zq Same Name Other Edition', 500, 'Zqtakenback');
        $this->pair($import);

        $found = $this->importPuzzle($import, 'Zq Same Name Other Edition');
        self::assertSame(['found', 'catalogue', $wrong, 'automatic'], [$found['tier'], $found['choice'], $found['puzzle_id'], $found['decided_how']]);

        // "Not this one"
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($found['id'], ImportPuzzleChoice::Undecided, null, $found['decision_version'])]);

        // The sheet is left out and taken in again: the import's puzzle is a new row
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET state = 'left_out' WHERE id = :id", ['id' => $row]);
        $this->pair($import);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id', ['id' => $import->id->toString()]));
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET state = 'waiting' WHERE id = :id", ['id' => $row]);
        $this->pair($import);

        // The rules still say "found" - and decide nothing: it waits for the player, with its candidate
        foreach ([$import, $nextFile] as $index => $file) {
            if ($index === 1) {
                $this->pair($file);
            }

            $again = $this->importPuzzle($file, 'Zq Same Name Other Edition');
            self::assertNotSame($found['id'], $again['id']);
            self::assertSame(['found', 'undecided', null, null], [$again['tier'], $again['choice'], $again['puzzle_id'], $again['decided_how']]);
            self::assertSame([$wrong], self::candidateIds($again));
        }

        // Until the player says it is that puzzle after all
        $again = $this->importPuzzle($import, 'Zq Same Name Other Edition');
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($again['id'], ImportPuzzleChoice::Catalogue, $wrong, $again['decision_version'])]);
        $this->pair($nextFile);
        self::assertSame(['catalogue', $wrong, 'remembered'], [$this->importPuzzle($nextFile, 'Zq Same Name Other Edition')['choice'], $this->importPuzzle($nextFile, 'Zq Same Name Other Edition')['puzzle_id'], $this->importPuzzle($nextFile, 'Zq Same Name Other Edition')['decided_how']]);
    }

    public function testEveryChangeOfADecisionHasItsOwnVersionEvenWithinOneSecond(): void
    {
        $player = $this->seedPairingPlayer('versions');
        $puzzle = $this->seedCataloguePuzzle('Zq Versioned Decision', 500);
        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Versioned Decision', 500);
        $this->pair($import);
        $id = $this->importPuzzle($import, 'Zq Versioned Decision')['id'];

        // The clock stands still: three changes in the same second
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($id, ImportPuzzleChoice::Catalogue, $puzzle, 0)]);
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($id, ImportPuzzleChoice::LeftOut, null, 1)]);
        self::assertSame([2, 'left_out'], [$this->importPuzzle($import, 'Zq Versioned Decision')['decision_version'], $this->importPuzzle($import, 'Zq Versioned Decision')['choice']]);

        // A page that saw the first of them is stale although `decided_at` is the same
        try {
            $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($id, ImportPuzzleChoice::Unlisted, null, 1)]);
            self::fail('A stale decision was taken.');
        } catch (ImportPuzzleDecidedMeanwhile) {
        }

        // "Not this puzzle" is nothing a page can send
        self::assertSame(ImportPuzzleChoiceRefused::INVALID, $this->refusal($import, $player->id->toString(), [new ImportPuzzleDecisionChange($id, ImportPuzzleChoice::NotThisPuzzle, $puzzle, 2)]));
    }

    public function testALaterRunOfThePairingKeepsThePlayersDecisions(): void
    {
        $player = $this->seedPairingPlayer('reparse');
        $brand = $this->seedBrand('Zqreparse');
        $found = $this->seedCataloguePuzzle('Zq Found By The Rules', 500, $brand);
        $picked = $this->seedCataloguePuzzle('Zq Picked By Hand', 500, $brand);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Found By The Rules', 500, 'Zqreparse');
        $this->seedRow($import, $sheet, 'Zq Unticked By Me', 500, 'Zqreparse', ['puzzleId' => $picked]);
        $leavesTheFile = $this->seedRow($import, $sheet, 'Zq Gone And Back Again', 500);
        $this->pair($import);

        $rules = $this->importPuzzle($import, 'Zq Found By The Rules');
        $unticked = $this->importPuzzle($import, 'Zq Unticked By Me');
        self::assertSame(['automatic', 'automatic'], [$rules['decided_how'], $unticked['decided_how']]);

        $this->decide($import, $player->id->toString(), [
            // Overruled: the rules said one puzzle, the player says another
            new ImportPuzzleDecisionChange($rules['id'], ImportPuzzleChoice::Catalogue, $picked, $rules['decision_version']),
        ]);
        $this->decide($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($unticked['id'], ImportPuzzleChoice::Undecided, null, $unticked['decision_version']),
            new ImportPuzzleDecisionChange($this->importPuzzle($import, 'Zq Gone And Back Again')['id'], ImportPuzzleChoice::LeftOut, null, 0),
        ]);

        $counters = $this->pairingCounters($import);
        self::assertEquals(['decided' => 3, 'foundOverruled' => 2, 'noneOfThese' => 0, 'pickedAnother' => 0, 'foundByHand' => 0], $counters['player']);

        // The sheet is parsed again: one puzzle is not in the file any more
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET state = 'left_out' WHERE id = :id", ['id' => $leavesTheFile]);
        $this->pair($import);

        self::assertSame(['catalogue', $picked, 'player'], [$this->importPuzzle($import, 'Zq Found By The Rules')['choice'], $this->importPuzzle($import, 'Zq Found By The Rules')['puzzle_id'], $this->importPuzzle($import, 'Zq Found By The Rules')['decided_how']]);
        self::assertSame(['found', 'undecided', 'player'], [$this->importPuzzle($import, 'Zq Unticked By Me')['tier'], $this->importPuzzle($import, 'Zq Unticked By Me')['choice'], $this->importPuzzle($import, 'Zq Unticked By Me')['decided_how']]);
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND name = 'Zq Gone And Back Again'", ['id' => $import->id->toString()]));
        self::assertNull($this->pairingDatabase()->fetchOne('SELECT import_puzzle_id FROM data_import_row WHERE id = :id', ['id' => $leavesTheFile]));

        $recounted = $this->pairingCounters($import);
        self::assertEquals(['decided' => 2, 'foundOverruled' => 2, 'noneOfThese' => 0, 'pickedAnother' => 0, 'foundByHand' => 0], $recounted['player']);
        self::assertSame(2, $recounted['tiers']['found']);

        // ... and when it is back, what the player said about it is too
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET state = 'waiting' WHERE id = :id", ['id' => $leavesTheFile]);
        $this->pair($import);

        $back = $this->importPuzzle($import, 'Zq Gone And Back Again');
        self::assertSame(['left_out', 'remembered'], [$back['choice'], $back['decided_how']]);
        self::assertSame($found, $this->importPuzzle($import, 'Zq Found By The Rules')['candidates'][0]['puzzleId']);
    }

    public function testSomebodyElsesImportIsNotFound(): void
    {
        $owner = $this->seedPairingPlayer('owner');
        $intruder = $this->seedPairingPlayer('intruder');
        $puzzle = $this->seedCataloguePuzzle('Zq Not Yours To Decide', 500);

        [$import, $sheet] = $this->seedImport($owner);
        $this->seedRow($import, $sheet, 'Zq Not Yours To Decide', 500);
        $this->pair($import);
        $importPuzzle = $this->importPuzzle($import, 'Zq Not Yours To Decide');

        try {
            $this->decide($import, $intruder->id->toString(), [new ImportPuzzleDecisionChange($importPuzzle['id'], ImportPuzzleChoice::Catalogue, $puzzle, 0)]);
            self::fail('Another player decided about the import.');
        } catch (DataImportNotFound) {
        }

        self::assertSame('undecided', $this->importPuzzle($import, 'Zq Not Yours To Decide')['choice']);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM import_puzzle_decision WHERE player_id = :id', ['id' => $intruder->id->toString()]));
    }

    public function testAStalePageChangesNothing(): void
    {
        $player = $this->seedPairingPlayer('stale');
        $first = $this->seedCataloguePuzzle('Zq Stale One', 500);
        $second = $this->seedCataloguePuzzle('Zq Stale Two', 500);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Stale One', 500);
        $this->seedRow($import, $sheet, 'Zq Stale Two', 500);
        $this->pair($import);
        $one = $this->importPuzzle($import, 'Zq Stale One');
        $two = $this->importPuzzle($import, 'Zq Stale Two');

        // Another tab decided the first one meanwhile
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($one['id'], ImportPuzzleChoice::LeftOut, null, 0)]);

        try {
            $this->decide($import, $player->id->toString(), [
                new ImportPuzzleDecisionChange($two['id'], ImportPuzzleChoice::Catalogue, $second, 0),
                new ImportPuzzleDecisionChange($one['id'], ImportPuzzleChoice::Catalogue, $first, 0),
            ]);
            self::fail('A stale decision was taken.');
        } catch (ImportPuzzleDecidedMeanwhile $stale) {
            self::assertSame([$one['id']], $stale->importPuzzleIds);
        }

        $this->pairingEntityManager()->clear();
        self::assertSame('left_out', $this->importPuzzle($import, 'Zq Stale One')['choice']);
        // All or nothing: the fresh decision of the same message was not written either
        self::assertSame('undecided', $this->importPuzzle($import, 'Zq Stale Two')['choice']);
    }

    public function testAPuzzleThatMayNotBeShownOrHasAnotherPieceCountIsRefused(): void
    {
        $player = $this->seedPairingPlayer('refused');
        $somebody = $this->seedPairingPlayer('somebody');
        $future = TestClock::now()->modify('+30 days');
        $hidden = $this->seedCataloguePuzzle('Zq Hidden From Everybody', 500, hideUntil: $future, hideImageUntil: $future);
        $unapproved = $this->seedCataloguePuzzle('Zq Waiting For Approval', 500, approved: false, addedBy: $somebody);
        $thousand = $this->seedCataloguePuzzle('Zq A Thousand Pieces', 1000);
        $fiveHundred = $this->seedCataloguePuzzle('Zq Five Hundred Pieces', 500);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq my five hundred', 500);
        $this->seedRow($import, $sheet, 'Zq my other five hundred', 500);
        $this->pair($import);
        $mine = $this->importPuzzle($import, 'Zq my five hundred');
        $other = $this->importPuzzle($import, 'Zq my other five hundred');

        foreach ([$hidden, $unapproved, '018d0003-ffff-0000-0000-00000000dead'] as $notChoosable) {
            self::assertSame(ImportPuzzleChoiceRefused::NOT_CHOOSABLE, $this->refusal($import, $player->id->toString(), [
                new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, $notChoosable, 0),
            ]));
        }

        self::assertSame(ImportPuzzleChoiceRefused::INVALID, $this->refusal($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, null, 0),
        ]));
        self::assertSame(ImportPuzzleChoiceRefused::UNKNOWN_PUZZLE, $this->refusal($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange('018d0003-ffff-0000-0000-00000000dead', ImportPuzzleChoice::LeftOut, null, 0),
        ]));
        // Another piece count: never in a group ...
        self::assertSame(ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_IN_GROUP, $this->refusal($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, $thousand, 0),
            new ImportPuzzleDecisionChange($other['id'], ImportPuzzleChoice::Catalogue, $fiveHundred, 0),
        ]));
        self::assertSame(['undecided', 'undecided'], [$this->importPuzzle($import, 'Zq my five hundred')['choice'], $this->importPuzzle($import, 'Zq my other five hundred')['choice']]);

        // ... only one by one, and only with the player's yes to "choose it anyway?"
        self::assertSame(ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_UNCONFIRMED, $this->refusal($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, $thousand, 0),
        ]));
        self::assertSame('undecided', $this->importPuzzle($import, 'Zq my five hundred')['choice']);
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, $thousand, 0, confirmedOtherPieces: true)]);
        self::assertSame(['catalogue', $thousand], [$this->importPuzzle($import, 'Zq my five hundred')['choice'], $this->importPuzzle($import, 'Zq my five hundred')['puzzle_id']]);
    }

    private function handledWithResult(RequestImportPairing $message): bool
    {
        $queued = self::getContainer()->get(MessageBusInterface::class)->dispatch($message)->last(HandledStamp::class)?->getResult();
        $this->pairingEntityManager()->clear();

        return $queued === true;
    }

    /**
     * @param list<ImportPuzzleDecisionChange> $decisions
     */
    private function decide(DataImport $import, string $playerId, array $decisions): void
    {
        $this->handled(self::getContainer()->get(DecideImportPuzzlesHandler::class), new DecideImportPuzzles($import->id->toString(), $playerId, $decisions));
    }

    /**
     * @param list<ImportPuzzleDecisionChange> $decisions
     */
    private function refusal(DataImport $import, string $playerId, array $decisions): string
    {
        try {
            $this->decide($import, $playerId, $decisions);
        } catch (ImportPuzzleChoiceRefused $refused) {
            $this->pairingEntityManager()->clear();

            return $refused->reason;
        }

        self::fail('The decision was taken.');
    }
}
