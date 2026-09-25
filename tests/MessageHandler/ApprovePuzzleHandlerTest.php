<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleApproval;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyApproved;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApprovePuzzleHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testApprovesWithCorrectionsAndRecordsWhoApproved(): void
    {
        $this->approve(
            brandChoice: PuzzleApprovalBrandChoice::Approve,
            name: '  Corrected name ',
            piecesCount: 500,
            ean: ' 4005556123456, 4005556123457 ',
            identificationNumber: '',
        );

        $puzzle = $this->puzzle(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertTrue($puzzle->approved);
        self::assertSame('Corrected name', $puzzle->name);
        self::assertSame(500, $puzzle->piecesCount);
        self::assertSame('4005556123456, 4005556123457', $puzzle->ean);
        self::assertNull($puzzle->identificationNumber);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $puzzle->approvedBy?->id->toString());
        self::assertNotNull($puzzle->approvedAt);

        $decision = $this->decisions(PuzzleModerationAction::PuzzleApproved)[0];
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $decision->decidedById->toString());
        self::assertNotNull($decision->decidedByCode);
        self::assertSame(PuzzleFixture::PUZZLE_UNAPPROVED, $decision->puzzleId?->toString());
        $before = $decision->details['before'] ?? null;
        self::assertIsArray($before);
        self::assertSame('Puzzle 20', $before['name'] ?? null);
    }

    public function testApprovingTheBrandApprovesItToo(): void
    {
        $this->approve(brandChoice: PuzzleApprovalBrandChoice::Approve);

        self::assertTrue($this->manufacturer(ManufacturerFixture::MANUFACTURER_UNAPPROVED)?->approved);
        self::assertCount(1, $this->decisions(PuzzleModerationAction::BrandApproved));
    }

    public function testMergingTheBrandMovesItsPuzzlesAndDeletesIt(): void
    {
        $this->approve(
            brandChoice: PuzzleApprovalBrandChoice::MergeInto,
            targetManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
        );

        self::assertSame(
            ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            $this->puzzle(PuzzleFixture::PUZZLE_UNAPPROVED)->manufacturer?->id->toString(),
        );
        self::assertNull($this->manufacturer(ManufacturerFixture::MANUFACTURER_UNAPPROVED));

        $decision = $this->decisions(PuzzleModerationAction::BrandMerged)[0];
        self::assertSame('Unknown Brand', $decision->details['mergedManufacturerName'] ?? null);
        self::assertSame(1, $decision->details['movedPuzzles'] ?? null);
    }

    public function testUsingAnExistingBrandMovesOnlyThePuzzle(): void
    {
        $this->approve(
            brandChoice: PuzzleApprovalBrandChoice::UseExisting,
            targetManufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
        );

        self::assertSame(
            ManufacturerFixture::MANUFACTURER_TREFL,
            $this->puzzle(PuzzleFixture::PUZZLE_UNAPPROVED)->manufacturer?->id->toString(),
        );

        $brand = $this->manufacturer(ManufacturerFixture::MANUFACTURER_UNAPPROVED);
        self::assertNotNull($brand);
        self::assertFalse($brand->approved);
    }

    public function testAnAlreadyApprovedPuzzleIsRefused(): void
    {
        try {
            $this->messageBus->dispatch(new ApprovePuzzle(
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                name: 'Anything',
                piecesCount: 500,
                ean: null,
                identificationNumber: null,
            ));
            self::fail('Expected the approval to be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(PuzzleAlreadyApproved::class, $exception->getPrevious());
        }
    }

    public function testMergingIntoAnUnapprovedBrandChangesNothing(): void
    {
        try {
            $this->approve(
                brandChoice: PuzzleApprovalBrandChoice::MergeInto,
                targetManufacturerId: ManufacturerFixture::MANUFACTURER_UNAPPROVED,
            );
            self::fail('Expected the approval to be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidPuzzleApproval::class, $exception->getPrevious());
        }

        $this->entityManager->clear();
        self::assertFalse($this->puzzle(PuzzleFixture::PUZZLE_UNAPPROVED)->approved);
        self::assertSame([], $this->decisions(PuzzleModerationAction::PuzzleApproved));
    }

    private function approve(
        PuzzleApprovalBrandChoice $brandChoice,
        null|string $targetManufacturerId = null,
        string $name = 'Puzzle 20',
        int $piecesCount = 1000,
        null|string $ean = null,
        null|string $identificationNumber = null,
    ): void {
        $this->messageBus->dispatch(new ApprovePuzzle(
            puzzleId: PuzzleFixture::PUZZLE_UNAPPROVED,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            name: $name,
            piecesCount: $piecesCount,
            ean: $ean,
            identificationNumber: $identificationNumber,
            brandChoice: $brandChoice,
            targetManufacturerId: $targetManufacturerId,
        ));

        $this->entityManager->clear();
    }

    private function puzzle(string $id): Puzzle
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $id);
        self::assertNotNull($puzzle);

        return $puzzle;
    }

    private function manufacturer(string $id): null|Manufacturer
    {
        return $this->entityManager->find(Manufacturer::class, $id);
    }

    /**
     * @return list<PuzzleModerationDecision>
     */
    private function decisions(PuzzleModerationAction $action): array
    {
        return $this->entityManager->getRepository(PuzzleModerationDecision::class)->findBy(['action' => $action]);
    }
}
