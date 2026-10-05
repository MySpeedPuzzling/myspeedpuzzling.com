<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\ManufacturerSlugRedirect;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Suggest a change" with a brand typed by the player - how a misspelled brand gets its right name
 * (ChangeRequestCreatedBrandSettler).
 */
final class ChangeRequestCreatedBrandTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleChangeRequestRepository $changeRequestRepository;
    private PuzzleRepository $puzzleRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->changeRequestRepository = $container->get(PuzzleChangeRequestRepository::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testATypedNameNoBrandMatchesCreatesAnUnapprovedBrand(): void
    {
        $changeRequestId = $this->proposeBrand(PuzzleFixture::PUZZLE_500_01, '  Ravensburger   Junior ');

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        self::assertNotNull($changeRequest->proposedManufacturer);
        self::assertSame('Ravensburger Junior', $changeRequest->proposedManufacturer->name);
        self::assertFalse($changeRequest->proposedManufacturer->approved);
        self::assertSame('Ravensburger Junior', $changeRequest->createdManufacturerName);
    }

    public function testATypedNameOfAnExistingBrandProposesThatBrand(): void
    {
        $changeRequestId = $this->proposeBrand(PuzzleFixture::PUZZLE_500_01, 'trefl');

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $changeRequest->proposedManufacturer?->id->toString());
        self::assertNull($changeRequest->createdManufacturerName);
    }

    public function testApprovingARenameMergesTheEmptiedBrandIntoTheNewOne(): void
    {
        // "Unknown Brand" has this one puzzle only
        $changeRequestId = $this->proposeBrand(PuzzleFixture::PUZZLE_UNAPPROVED, 'Known Brand');
        $createdId = $this->changeRequestRepository->get($changeRequestId)->proposedManufacturer?->id->toString();
        self::assertNotNull($createdId);

        $this->approve($changeRequestId, PuzzleFixture::PUZZLE_UNAPPROVED);
        $this->entityManager->clear();

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertSame($createdId, $puzzle->manufacturer?->id->toString());
        self::assertTrue($puzzle->manufacturer->approved);

        self::assertNull($this->entityManager->find(Manufacturer::class, ManufacturerFixture::MANUFACTURER_UNAPPROVED));
        $redirect = $this->entityManager->find(ManufacturerSlugRedirect::class, 'unknown-brand');
        self::assertNotNull($redirect);
        self::assertSame($createdId, $redirect->manufacturer->id->toString());

        // The merged brand was not approved - the new one is approved on its own
        self::assertSame(['brand_approved', 'brand_merged', 'change_request_approved'], $this->decisionsOf($changeRequestId));
    }

    public function testApprovingANewBrandKeepsTheOldOneWhileItHasOtherPuzzles(): void
    {
        $changeRequestId = $this->proposeBrand(PuzzleFixture::PUZZLE_500_01, 'Ravensburger Junior');

        $this->approve($changeRequestId, PuzzleFixture::PUZZLE_500_01);
        $this->entityManager->clear();

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Ravensburger Junior', $puzzle->manufacturer?->name);
        self::assertTrue($puzzle->manufacturer->approved);
        self::assertNotNull($this->entityManager->find(Manufacturer::class, ManufacturerFixture::MANUFACTURER_RAVENSBURGER));
    }

    public function testRejectingDeletesTheBrandItCreatedAndKeepsItsName(): void
    {
        $changeRequestId = $this->proposeBrand(PuzzleFixture::PUZZLE_500_01, 'Ravensburgr');
        $createdId = $this->changeRequestRepository->get($changeRequestId)->proposedManufacturer?->id->toString();
        self::assertNotNull($createdId);

        $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            rejectionReason: 'The brand is spelled right.',
        ));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(Manufacturer::class, $createdId));

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);
        self::assertNull($changeRequest->proposedManufacturer);
        self::assertSame('Ravensburgr', $changeRequest->createdManufacturerName);
        self::assertSame(['brand_deleted', 'change_request_rejected'], $this->decisionsOf($changeRequestId));
    }

    public function testApprovingWithoutTheBrandDeletesIt(): void
    {
        $changeRequestId = $this->proposeBrand(PuzzleFixture::PUZZLE_500_01, 'Ravensburgr');
        $createdId = $this->changeRequestRepository->get($changeRequestId)->proposedManufacturer?->id->toString();
        self::assertNotNull($createdId);

        // The brand not selected - the puzzle keeps its own
        $this->approve($changeRequestId, PuzzleFixture::PUZZLE_500_01, selectedFields: ['name']);
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(Manufacturer::class, $createdId));
        self::assertSame(
            ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->manufacturer?->id->toString(),
        );
    }

    public function testABrandSomebodyElseUsedMeanwhileIsKept(): void
    {
        $changeRequestId = $this->proposeBrand(PuzzleFixture::PUZZLE_500_01, 'Ravensburgr');
        $created = $this->changeRequestRepository->get($changeRequestId)->proposedManufacturer;
        self::assertNotNull($created);
        $createdId = $created->id->toString();

        // Another puzzle was added with the same brand
        $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_02)->manufacturer = $created;
        $this->entityManager->flush();

        $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            rejectionReason: 'Not this puzzle.',
        ));
        $this->entityManager->clear();

        self::assertNotNull($this->entityManager->find(Manufacturer::class, $createdId));
    }

    private function proposeBrand(string $puzzleId, string $brand): string
    {
        $changeRequestId = Uuid::uuid7()->toString();
        $puzzle = $this->puzzleRepository->get($puzzleId);

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: $puzzleId,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: $puzzle->name,
            proposedBrand: $brand,
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEans: $puzzle->eans(),
            proposedBrandCodes: $puzzle->brandCodes(),
            proposedPhoto: null,
            originalAlternativeNames: new PuzzleNames(),
            originalNameLanguage: $puzzle->nameLanguage,
        ));

        return $changeRequestId;
    }

    /**
     * @param list<string> $selectedFields
     */
    private function approve(string $changeRequestId, string $puzzleId, array $selectedFields = ['manufacturer']): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: $puzzleId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: $selectedFields,
        ));
    }

    /**
     * The decisions recorded for the change request, sorted - recorded in the same second
     *
     * @return list<string>
     */
    private function decisionsOf(string $changeRequestId): array
    {
        $decisions = $this->entityManager->getRepository(PuzzleModerationDecision::class)->findBy(
            ['changeRequestId' => Uuid::fromString($changeRequestId)],
        );

        $actions = array_map(static fn (PuzzleModerationDecision $decision): string => $decision->action->value, $decisions);
        sort($actions);

        return $actions;
    }
}
