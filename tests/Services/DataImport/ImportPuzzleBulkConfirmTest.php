<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\MessageHandler\DecideImportPuzzlesHandler;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Results\ImportPuzzleCard;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleBulkConfirm;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Yes, these N are right" (docs/features/data-import/guided-flow.md §6.1) - which puzzles may be confirmed several
 * at once. A wrong match puts a real result on the wrong puzzle's public leaderboard, so the set ("part A") is
 * narrow and it is **the server's**: the grid's statement holds nothing else, and the handler refuses a bulk
 * confirm that holds anything else, whatever a form sends. The rule is said twice - in SQL and in PHP - and must
 * say the same. Every puzzle here is made up.
 */
final class ImportPuzzleBulkConfirmTest extends KernelTestCase
{
    use ImportPairingScenario;

    /** The puzzles of the file that the rules call "probably" => whether each may be confirmed with others */
    private const array PART_A = [
        'Zq8 Same Name No Brand' => true,
        'Zq8 box with a barcode' => true,
        'Zq8 box with a brand code' => true,
        'Zq8 Other Brand Named' => false,
        'Zq8 Unknown Brand Named' => false,
        'Zq8 No Piece Count' => false,
        'Zq8 Paris (Panorama)' => false,
        'ZQ8' => false,
    ];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testPartAIsTheSamePuzzleWithLessProofAndNothingElseInSqlAndInPhpAlike(): void
    {
        [$import, $playerId, $catalogue] = $this->pile();
        $importId = $import->id->toString();
        $screen = self::getContainer()->get(GetImportPuzzleScreen::class);

        foreach (array_keys(self::PART_A) as $name) {
            self::assertSame(['probably', 'undecided'], [$this->importPuzzle($importId, $name)['tier'], $this->importPuzzle($importId, $name)['choice']], $name);
        }

        $same = $screen->partPage($importId, $playerId, GetImportPuzzleScreen::PART_SAME, 50, null);
        $differs = $screen->partPage($importId, $playerId, GetImportPuzzleScreen::PART_DIFFERS, 50, null);

        self::assertEqualsCanonicalizing(array_keys(array_filter(self::PART_A)), self::names($same));
        self::assertEqualsCanonicalizing(array_keys(array_filter(self::PART_A, static fn (bool $partA): bool => $partA === false)), self::names($differs));
        self::assertSame(['same' => 3, 'differs' => 5, 'sameBefore' => 0, 'differsBefore' => 0], $screen->partCounts($importId, $playerId, null));

        // The guard of the slice: part A never holds a card whose brand or piece count differs, and each of its
        // cards has exactly one puzzle it could be
        foreach ($same as $card) {
            self::assertCount(1, $card->candidates, $card->name);
            self::assertFalse($card->candidates[0]->brandDiffers, $card->name);
            self::assertFalse($card->candidates[0]->piecesDiffer, $card->name);
            self::assertContains($card->candidates[0]->how, ['name', 'barcode', 'brand_code'], $card->name);
        }

        // The same rule in PHP, for the handler: puzzle by puzzle the same answer
        foreach (self::PART_A as $name => $partA) {
            self::assertSame($partA, $this->isPartA($importId, $name, $catalogue[$name]), $name);
        }

        // ... and for a puzzle that is not the one the rules found
        self::assertFalse($this->isPartA($importId, 'Zq8 Same Name No Brand', $catalogue['Zq8 box with a barcode']));
    }

    public function testAPuzzleLeavesPartAWhenItsPieceCountOrBrandDiffersOrASecondPuzzleFits(): void
    {
        [$import, $playerId, $catalogue] = $this->pile();
        $importId = $import->id->toString();
        $screen = self::getContainer()->get(GetImportPuzzleScreen::class);
        $name = 'Zq8 Same Name No Brand';
        $id = $this->importPuzzle($importId, $name)['id'];
        $inPartA = fn (): bool => in_array($name, self::names($screen->partPage($importId, $playerId, GetImportPuzzleScreen::PART_SAME, 50, null)), true);
        $database = $this->pairingDatabase();
        self::assertTrue($inPartA());

        // Another piece count (the catalogue's puzzle was corrected since the look-up)
        $database->executeStatement('UPDATE puzzle SET pieces_count = 1000 WHERE id = :id', ['id' => $catalogue[$name]]);
        self::assertFalse($inPartA());
        self::assertFalse($this->isPartA($importId, $name, $catalogue[$name]));
        $database->executeStatement('UPDATE puzzle SET pieces_count = 501 WHERE id = :id', ['id' => $catalogue[$name]]);
        self::assertTrue($inPartA(), 'one piece apart is the same count (a box says 501)');
        self::assertTrue($this->isPartA($importId, $name, $catalogue[$name]));

        // The file's brand stands for another brand than the puzzle's
        $database->executeStatement("UPDATE data_import_puzzle SET manufacturer_id = (SELECT id FROM manufacturer WHERE name = 'Zq8other') WHERE id = :id", ['id' => $id]);
        self::assertFalse($inPartA());
        self::assertFalse($this->isPartA($importId, $name, $catalogue[$name]));
        $database->executeStatement('UPDATE data_import_puzzle SET manufacturer_id = NULL WHERE id = :id', ['id' => $id]);
        self::assertTrue($inPartA());

        // A second puzzle fits
        $candidates = $this->importPuzzle($importId, $name)['candidates'];
        $database->executeStatement('UPDATE data_import_puzzle SET candidates = CAST(:candidates AS JSONB) WHERE id = :id', ['id' => $id, 'candidates' => json_encode([...$candidates, ['puzzleId' => $catalogue['Zq8 box with a barcode'], 'how' => 'name', 'score' => null, 'piecesDiffer' => false]])]);
        self::assertFalse($inPartA());
        self::assertFalse($this->isPartA($importId, $name, $catalogue[$name]));
        $database->executeStatement('UPDATE data_import_puzzle SET candidates = CAST(:candidates AS JSONB) WHERE id = :id', ['id' => $id, 'candidates' => json_encode($candidates)]);
        self::assertTrue($inPartA());

        // The puzzle is hidden since (a secret puzzle of an event): it is offered nowhere
        $database->executeStatement('UPDATE puzzle SET hide_until = :until WHERE id = :id', ['id' => $catalogue[$name], 'until' => TestClock::now()->modify('+30 days')->format('Y-m-d H:i:s')]);
        self::assertFalse($inPartA());
        $hidden = array_values(array_filter($screen->partPage($importId, $playerId, GetImportPuzzleScreen::PART_DIFFERS, 50, null), static fn (ImportPuzzleCard $card): bool => $card->name === $name));
        self::assertCount(1, $hidden);
        self::assertSame([], $hidden[0]->candidates, 'a hidden puzzle is never shown as what it could be');

        // It was decided: it waits no more, so it is in no part
        $database->executeStatement('UPDATE puzzle SET hide_until = NULL WHERE id = :id', ['id' => $catalogue[$name]]);
        $this->decide($importId, $playerId, [new ImportPuzzleDecisionChange($id, ImportPuzzleChoice::LeftOut, null, 0)]);
        self::assertFalse($inPartA());
        self::assertFalse($this->isPartA($importId, $name, $catalogue[$name]));
        self::assertSame(2, $screen->partCounts($importId, $playerId, null)['same']);
    }

    public function testABulkConfirmHoldingAnythingButPartAIsRefusedWholeAndNothingIsWritten(): void
    {
        [$import, $playerId, $catalogue] = $this->pile();
        $importId = $import->id->toString();
        $change = fn (string $name, null|string $puzzle = null): ImportPuzzleDecisionChange => new ImportPuzzleDecisionChange($this->importPuzzle($importId, $name)['id'], ImportPuzzleChoice::Catalogue, $puzzle ?? $catalogue[$name], 0);
        $partA = [$change('Zq8 Same Name No Brand'), $change('Zq8 box with a barcode'), $change('Zq8 box with a brand code')];

        // Each puzzle that is not part A - another brand, a brand we do not know, no piece count, a name without
        // its brackets, a short name - refuses the whole set, whether the message says "bulk" or not
        foreach (array_keys(array_filter(self::PART_A, static fn (bool $isPartA): bool => $isPartA === false)) as $name) {
            foreach ([true, false] as $saidToBeBulk) {
                self::assertSame(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $this->refusal($importId, $playerId, [...$partA, $change($name)], $saidToBeBulk), $name);
            }

            // ... and alone, sent as "these are right"
            self::assertSame(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $this->refusal($importId, $playerId, [$change($name)], true), $name);
        }

        // A puzzle the rules did not find for it; a puzzle to pick among several; one that is not in the database
        self::assertSame(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $this->refusal($importId, $playerId, [$partA[0], $change('Zq8 box with a barcode', $catalogue['Zq8 Other Brand Named'])], true));
        self::assertSame('choose', $this->importPuzzle($importId, 'Zq8 Twin Editions')['tier']);
        self::assertSame(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $this->refusal($importId, $playerId, [$partA[0], $change('Zq8 Twin Editions', $catalogue['Zq8 Twin Editions'])], true));
        self::assertSame('none', $this->importPuzzle($importId, 'Zq8 Nobody Knows Qxv')['tier']);
        self::assertSame(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $this->refusal($importId, $playerId, [$partA[0], $change('Zq8 Nobody Knows Qxv', $catalogue['Zq8 Paris (Panorama)'])], true));

        // A decision that names no puzzle has no place in "these are right"
        self::assertSame(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $this->refusal($importId, $playerId, [$partA[0], new ImportPuzzleDecisionChange($this->importPuzzle($importId, 'Zq8 Nobody Knows Qxv')['id'], ImportPuzzleChoice::Unlisted, null, 0)], true));

        // Another piece count: refused in a set as before - and alone as "these are right" too
        $this->pairingDatabase()->executeStatement('UPDATE puzzle SET pieces_count = 1000 WHERE id = :id', ['id' => $catalogue['Zq8 Same Name No Brand']]);
        self::assertSame(ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_IN_GROUP, $this->refusal($importId, $playerId, $partA, true));
        self::assertContains($this->refusal($importId, $playerId, [$partA[0]], true), [ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_UNCONFIRMED]);
        $this->pairingDatabase()->executeStatement('UPDATE puzzle SET pieces_count = 500 WHERE id = :id', ['id' => $catalogue['Zq8 Same Name No Brand']]);

        // A hidden puzzle: never chosen
        $this->pairingDatabase()->executeStatement('UPDATE puzzle SET hide_until = :until WHERE id = :id', ['id' => $catalogue['Zq8 box with a barcode'], 'until' => TestClock::now()->modify('+30 days')->format('Y-m-d H:i:s')]);
        self::assertSame(ImportPuzzleChoiceRefused::NOT_CHOOSABLE, $this->refusal($importId, $playerId, $partA, true));
        $this->pairingDatabase()->executeStatement('UPDATE puzzle SET hide_until = NULL WHERE id = :id', ['id' => $catalogue['Zq8 box with a barcode']]);

        // Nothing of all that was written
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND decided_how = 'player'", ['id' => $importId]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM import_puzzle_decision WHERE player_id = :id', ['id' => $playerId]));

        // A stale version in the set: refused whole as "decided meanwhile"
        try {
            $this->decide($importId, $playerId, [$partA[0], new ImportPuzzleDecisionChange($partA[1]->importPuzzleId, ImportPuzzleChoice::Catalogue, $partA[1]->puzzleId, 7)], true);
            self::fail('A stale set was taken.');
        } catch (ImportPuzzleDecidedMeanwhile) {
            $this->pairingEntityManager()->clear();
        }

        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND decided_how = 'player'", ['id' => $importId]));

        // Part A alone is taken - one message, all of it
        $this->decide($importId, $playerId, $partA, true);

        foreach (array_keys(array_filter(self::PART_A)) as $name) {
            $decided = $this->importPuzzle($importId, $name);
            self::assertSame(['catalogue', $catalogue[$name], 'player'], [$decided['choice'], $decided['puzzle_id'], $decided['decided_how']], $name);
        }

        self::assertSame(3, $this->pairingCount("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND decided_how = 'player'", ['id' => $importId]));

        // What is decided can not be "confirmed" again in a set (it waits no more)
        $again = new ImportPuzzleDecisionChange($partA[0]->importPuzzleId, ImportPuzzleChoice::Catalogue, $partA[0]->puzzleId, 1);
        self::assertSame(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $this->refusal($importId, $playerId, [$again], true));

        // One puzzle that needs a closer look, confirmed on its own - the player's deliberate tap on its card - is
        // taken as before
        $this->decide($importId, $playerId, [$change('Zq8 Other Brand Named')]);
        self::assertSame('catalogue', $this->importPuzzle($importId, 'Zq8 Other Brand Named')['choice']);
    }

    /**
     * An import whose file names a puzzle for every reason the rules say "probably" - and one to pick among two,
     * and one nobody knows.
     *
     * @return array{DataImport, string, array<string, string>} the import, its player, the file's name => the catalogue puzzle
     */
    private function pile(): array
    {
        $player = $this->seedPairingPlayer('bulk');
        $brand = $this->seedBrand('Zq8brand');
        $other = $this->seedBrand('Zq8other');
        $barcode = self::ean('299998800001');

        $catalogue = [
            'Zq8 Same Name No Brand' => $this->seedCataloguePuzzle('Zq8 Same Name No Brand', 500, $brand),
            'Zq8 box with a barcode' => $this->seedCataloguePuzzle('Zq8 Lighthouse At Night', 1000, $brand, eans: [$barcode]),
            'Zq8 box with a brand code' => $this->seedCataloguePuzzle('Zq8 Mountain Railway', 500, $brand, brandCodes: ['88321']),
            'Zq8 Other Brand Named' => $this->seedCataloguePuzzle('Zq8 Other Brand Named', 500, $brand),
            'Zq8 Unknown Brand Named' => $this->seedCataloguePuzzle('Zq8 Unknown Brand Named', 500, $brand),
            'Zq8 No Piece Count' => $this->seedCataloguePuzzle('Zq8 No Piece Count', 500, $brand),
            'Zq8 Paris (Panorama)' => $this->seedCataloguePuzzle('Zq8 Paris', 500, $brand),
            'ZQ8' => $this->seedCataloguePuzzle('Zq8', 500, $brand),
            'Zq8 Twin Editions' => $this->seedCataloguePuzzle('Zq8 Twin Editions', 1000, $brand),
        ];
        $this->seedCataloguePuzzle('Zq8 Twin Editions', 1000, $other);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq8 Same Name No Brand', 500);
        $this->seedRow($import, $sheet, 'Zq8 box with a barcode', null, null, ['barcodes' => [ltrim($barcode, '0')]]);
        $this->seedRow($import, $sheet, 'Zq8 box with a brand code', 500, 'Zq8brand', ['brandCodes' => ['88321']]);
        $this->seedRow($import, $sheet, 'Zq8 Other Brand Named', 500, 'Zq8other');
        $this->seedRow($import, $sheet, 'Zq8 Unknown Brand Named', 500, 'A brand nobody heard of Qxv');
        $this->seedRow($import, $sheet, 'Zq8 No Piece Count');
        $this->seedRow($import, $sheet, 'Zq8 Paris (Panorama)', 500);
        $this->seedRow($import, $sheet, 'ZQ8', 500);
        $this->seedRow($import, $sheet, 'Zq8 Twin Editions', 1000);
        $this->seedRow($import, $sheet, 'Zq8 Nobody Knows Qxv', 500);
        $this->pair($import);

        return [$import, $player->id->toString(), $catalogue];
    }

    private function isPartA(string $importId, string $name, string $cataloguePuzzleId): bool
    {
        $entityManager = $this->pairingEntityManager();
        $entityManager->clear();
        $importPuzzle = $entityManager->find(DataImportPuzzle::class, $this->importPuzzle($importId, $name)['id']);
        $puzzle = $entityManager->find(Puzzle::class, $cataloguePuzzleId);
        self::assertNotNull($importPuzzle);
        self::assertNotNull($puzzle);

        return ImportPuzzleBulkConfirm::isPartA($importPuzzle, $puzzle);
    }

    /**
     * @param list<ImportPuzzleDecisionChange> $decisions
     */
    private function decide(string $importId, string $playerId, array $decisions, bool $bulkConfirm = false): void
    {
        $this->handled(self::getContainer()->get(DecideImportPuzzlesHandler::class), new DecideImportPuzzles($importId, $playerId, $decisions, $bulkConfirm));
    }

    /**
     * @param list<ImportPuzzleDecisionChange> $decisions
     */
    private function refusal(string $importId, string $playerId, array $decisions, bool $bulkConfirm): string
    {
        try {
            $this->decide($importId, $playerId, $decisions, $bulkConfirm);
        } catch (ImportPuzzleChoiceRefused $refused) {
            $this->pairingEntityManager()->clear();

            return $refused->reason;
        }

        self::fail('The set was taken.');
    }

    /**
     * @param list<ImportPuzzleCard> $cards
     *
     * @return list<string>
     */
    private static function names(array $cards): array
    {
        return array_map(static fn (ImportPuzzleCard $card): string => $card->name, $cards);
    }
}
