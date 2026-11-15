<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\MessageHandler\DecideImportPuzzlesHandler;
use SpeedPuzzling\Web\Query\GetImportPairing;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportPairingScenario;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A puzzle merge moves what imports paired with the merged puzzle onto the survivor
 * (docs/features/data-import/pairing.md "A puzzle merge"): the import's own puzzle - the rules' decision and the
 * player's - and the player's remembered decision. Without it the merge fails on the first and forgets the second.
 */
final class ImportPuzzleMergeTest extends KernelTestCase
{
    use ImportPairingScenario;

    public function testAnImportsPuzzleAndARememberedDecisionMoveToTheSurvivor(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $player = $this->seedPairingPlayer('merge');
        $brand = $this->seedBrand('Zqmerge');
        $survivor = $this->seedCataloguePuzzle('Zq Merge Survivor', 500, $brand);
        $duplicate = $this->seedCataloguePuzzle('Zq Merge Duplicate', 500, $brand);
        $otherDuplicate = $this->seedCataloguePuzzle('Zq Merge Second Duplicate', 500, $brand);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Merge Duplicate', 500, 'Zqmerge');
        $this->seedRow($import, $sheet, 'Zq Merge Second Duplicate', 500, 'Zqmerge');
        $this->pair($import);

        $automatic = $this->importPuzzle($import, 'Zq Merge Second Duplicate');
        self::assertSame(['automatic', $otherDuplicate], [$automatic['decided_how'], $automatic['puzzle_id']]);

        // The player says yes to what the rules found: their own decision now, remembered
        $confirmed = $this->importPuzzle($import, 'Zq Merge Duplicate');
        $this->handled(self::getContainer()->get(DecideImportPuzzlesHandler::class), new DecideImportPuzzles($import->id->toString(), $player->id->toString(), [
            new ImportPuzzleDecisionChange($confirmed['id'], ImportPuzzleChoice::Catalogue, $duplicate, $confirmed['decision_version']),
        ]));

        $mergeRequestId = Uuid::uuid7()->toString();
        $bus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: $survivor,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [$duplicate, $otherDuplicate],
        ));
        $bus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: $survivor,
            mergedName: 'Zq Merge Survivor',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: $brand->id->toString(),
            selectedImagePuzzleId: null,
        ));

        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle WHERE id IN (:a, :b)', ['a' => $duplicate, 'b' => $otherDuplicate]));
        self::assertSame(
            [['choice' => 'catalogue', 'puzzle_id' => $survivor, 'decided_how' => 'automatic'], ['choice' => 'catalogue', 'puzzle_id' => $survivor, 'decided_how' => 'player']],
            $this->pairingDatabase()->fetchAllAssociative('SELECT choice, puzzle_id, decided_how FROM data_import_puzzle WHERE import_id = :id ORDER BY decided_how', ['id' => $import->id->toString()]),
        );
        self::assertSame(
            [['choice' => 'catalogue', 'puzzle_id' => $survivor]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT choice, puzzle_id FROM import_puzzle_decision WHERE player_id = :id', ['id' => $player->id->toString()]),
        );

        // The player's puzzle still lists the merged puzzle as its candidate: counted through the merge, it is the
        // puzzle the rules offered - nothing was overruled
        self::assertSame([$duplicate], self::candidateIds($this->importPuzzle($import, 'Zq Merge Duplicate')));
        $counters = self::getContainer()->get(GetImportPairing::class)->counters($import->id->toString());
        self::assertSame([1, 0], [$counters['player']['decided'], $counters['player']['foundOverruled']]);

        // The next run of the pairing finds the survivor under the merged puzzle's name (a merge keeps every name)
        $this->pair($import);
        $again = $this->importPuzzle($import, 'Zq Merge Second Duplicate');
        self::assertSame(['found', $survivor, 'automatic'], [$again['tier'], $again['puzzle_id'], $again['decided_how']]);
    }
}
