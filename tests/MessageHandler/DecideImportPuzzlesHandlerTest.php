<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
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
            new ImportPuzzleDecisionChange($probably['id'], ImportPuzzleChoice::Catalogue, $puzzle, null),
            new ImportPuzzleDecisionChange($this->importPuzzle($import, 'Zq Nothing Like It Anywhere')['id'], ImportPuzzleChoice::Unlisted, null, null),
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

        // Taken back: undecided by the player's own word, forgotten for the next file, and no run ticks it again
        $this->decide($next, $player->id->toString(), [
            new ImportPuzzleDecisionChange($remembered['id'], ImportPuzzleChoice::Undecided, null, new DateTimeImmutable((string) $remembered['decided_at'])),
        ]);
        $this->pair($next);

        $takenBack = $this->importPuzzle($next, 'zq remembered  HARBOUR');
        self::assertSame(['undecided', null, 'player'], [$takenBack['choice'], $takenBack['puzzle_id'], $takenBack['decided_how']]);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM import_puzzle_decision WHERE player_id = :id', ['id' => $player->id->toString()]));
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
            new ImportPuzzleDecisionChange($rules['id'], ImportPuzzleChoice::Catalogue, $picked, new DateTimeImmutable((string) $rules['decided_at'])),
        ]);
        $this->decide($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($unticked['id'], ImportPuzzleChoice::Undecided, null, new DateTimeImmutable((string) $unticked['decided_at'])),
            new ImportPuzzleDecisionChange($this->importPuzzle($import, 'Zq Gone And Back Again')['id'], ImportPuzzleChoice::LeftOut, null, null),
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
            $this->decide($import, $intruder->id->toString(), [new ImportPuzzleDecisionChange($importPuzzle['id'], ImportPuzzleChoice::Catalogue, $puzzle, null)]);
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
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($one['id'], ImportPuzzleChoice::LeftOut, null, null)]);

        try {
            $this->decide($import, $player->id->toString(), [
                new ImportPuzzleDecisionChange($two['id'], ImportPuzzleChoice::Catalogue, $second, null),
                new ImportPuzzleDecisionChange($one['id'], ImportPuzzleChoice::Catalogue, $first, null),
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
                new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, $notChoosable, null),
            ]));
        }

        self::assertSame(ImportPuzzleChoiceRefused::INVALID, $this->refusal($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, null, null),
        ]));
        self::assertSame(ImportPuzzleChoiceRefused::UNKNOWN_PUZZLE, $this->refusal($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange('018d0003-ffff-0000-0000-00000000dead', ImportPuzzleChoice::LeftOut, null, null),
        ]));
        // Another piece count: never in a group ...
        self::assertSame(ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_IN_GROUP, $this->refusal($import, $player->id->toString(), [
            new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, $thousand, null),
            new ImportPuzzleDecisionChange($other['id'], ImportPuzzleChoice::Catalogue, $fiveHundred, null),
        ]));
        self::assertSame(['undecided', 'undecided'], [$this->importPuzzle($import, 'Zq my five hundred')['choice'], $this->importPuzzle($import, 'Zq my other five hundred')['choice']]);

        // ... only one by one
        $this->decide($import, $player->id->toString(), [new ImportPuzzleDecisionChange($mine['id'], ImportPuzzleChoice::Catalogue, $thousand, null)]);
        self::assertSame(['catalogue', $thousand], [$this->importPuzzle($import, 'Zq my five hundred')['choice'], $this->importPuzzle($import, 'Zq my five hundred')['puzzle_id']]);
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
