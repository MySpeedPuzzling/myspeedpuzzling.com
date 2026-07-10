<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteMergedPuzzlesOnMergeApprovedTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleRepository $puzzleRepository;
    private PuzzleRedirectRepository $puzzleRedirectRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
        $this->puzzleRedirectRepository = $container->get(PuzzleRedirectRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testApprovingMergeCreatesRedirectFromDeletedPuzzleToSurvivor(): void
    {
        $this->mergePuzzles(
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
        );

        $this->entityManager->clear();

        // Deleted puzzle is gone
        try {
            $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
            self::fail('Expected PuzzleNotFound exception - duplicate puzzle should be deleted');
        } catch (PuzzleNotFound) {
            // Expected behavior
        }

        // Redirect from deleted puzzle to survivor exists
        $redirect = $this->puzzleRedirectRepository->findByOldPuzzleId(PuzzleFixture::PUZZLE_500_05);
        self::assertNotNull($redirect);
        self::assertSame(PuzzleFixture::PUZZLE_500_05, $redirect->oldPuzzleId->toString());
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $redirect->survivorPuzzleId->toString());

        // Survivor puzzle has no redirect
        self::assertNull($this->puzzleRedirectRepository->findByOldPuzzleId(PuzzleFixture::PUZZLE_500_04));
    }

    public function testMergeChainUpdatesExistingRedirectsToNewSurvivor(): void
    {
        // Fresh puzzles without any related records so the deletion handler can be tested in isolation
        $puzzleA = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Chain Puzzle A', approved: true);
        $puzzleB = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Chain Puzzle B', approved: true);
        $puzzleC = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Chain Puzzle C', approved: true);

        $this->entityManager->persist($puzzleA);
        $this->entityManager->persist($puzzleB);
        $this->entityManager->persist($puzzleC);
        $this->entityManager->flush();

        $puzzleAId = $puzzleA->id->toString();
        $puzzleBId = $puzzleB->id->toString();
        $puzzleCId = $puzzleC->id->toString();

        // First merge: A merged into B
        $this->messageBus->dispatch(
            new PuzzleMergeApproved(
                mergeRequestId: Uuid::uuid7(),
                survivorPuzzleId: $puzzleB->id,
                puzzleIdsToDelete: [$puzzleAId],
            ),
        );

        // Second merge: B (previous survivor) merged into C
        $this->messageBus->dispatch(
            new PuzzleMergeApproved(
                mergeRequestId: Uuid::uuid7(),
                survivorPuzzleId: $puzzleC->id,
                puzzleIdsToDelete: [$puzzleBId],
            ),
        );

        $this->entityManager->clear();

        // Redirect of the puzzle deleted in second merge points to the new survivor
        $secondRedirect = $this->puzzleRedirectRepository->findByOldPuzzleId($puzzleBId);
        self::assertNotNull($secondRedirect);
        self::assertSame($puzzleCId, $secondRedirect->survivorPuzzleId->toString());

        // Redirect chain: old redirect from first merge now points to the new survivor as well
        $chainedRedirect = $this->puzzleRedirectRepository->findByOldPuzzleId($puzzleAId);
        self::assertNotNull($chainedRedirect);
        self::assertSame($puzzleCId, $chainedRedirect->survivorPuzzleId->toString());

        // Both merged puzzles are deleted
        try {
            $this->puzzleRepository->get($puzzleBId);
            self::fail('Expected PuzzleNotFound exception - merged puzzle should be deleted');
        } catch (PuzzleNotFound) {
            // Expected behavior
        }
    }

    /**
     * @param array<string> $duplicatePuzzleIds
     */
    private function mergePuzzles(
        string $sourcePuzzleId,
        array $duplicatePuzzleIds,
        string $survivorPuzzleId,
    ): void {
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: $sourcePuzzleId,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: $duplicatePuzzleIds,
            ),
        );

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: $survivorPuzzleId,
                mergedName: 'Merged Puzzle Name',
                mergedEan: null,
                mergedIdentificationNumber: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                selectedImagePuzzleId: null,
            ),
        );
    }
}
