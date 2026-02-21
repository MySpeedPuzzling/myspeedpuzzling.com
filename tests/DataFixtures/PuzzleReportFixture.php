<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;

final class PuzzleReportFixture extends Fixture implements DependentFixtureInterface
{
    public const string CHANGE_REQUEST_PENDING = '018e0000-0000-0000-0000-000000000001';
    public const string CHANGE_REQUEST_APPROVED = '018e0000-0000-0000-0000-000000000002';
    public const string CHANGE_REQUEST_REJECTED = '018e0000-0000-0000-0000-000000000003';
    public const string CHANGE_REQUEST_WITH_IMAGE = '018e0000-0000-0000-0000-000000000004';

    public const string MERGE_REQUEST_PENDING = '018e0001-0000-0000-0000-000000000001';
    public const string MERGE_REQUEST_APPROVED = '018e0001-0000-0000-0000-000000000002';
    public const string MERGE_REQUEST_REJECTED = '018e0001-0000-0000-0000-000000000003';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $regularPlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $adminPlayer = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);
        $puzzle500_02 = $this->getReference(PuzzleFixture::PUZZLE_500_02, Puzzle::class);
        $puzzle500_03 = $this->getReference(PuzzleFixture::PUZZLE_500_03, Puzzle::class);
        $ravensburger = $this->getReference(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, Manufacturer::class);

        $now = $this->clock->now();

        // Pending change request
        $pendingChange = new PuzzleChangeRequest(
            id: Uuid::fromString(self::CHANGE_REQUEST_PENDING),
            puzzle: $puzzle500_01,
            reporter: $regularPlayer,
            submittedAt: $now,
            proposedName: 'Updated Puzzle Name',
            proposedManufacturer: null,
            proposedPiecesCount: null,
            proposedEan: '1234567890123',
            proposedIdentificationNumber: null,
            proposedImage: null,
            originalName: $puzzle500_01->name,
            originalManufacturerId: $puzzle500_01->manufacturer?->id,
            originalPiecesCount: $puzzle500_01->piecesCount,
            originalEan: $puzzle500_01->ean,
            originalIdentificationNumber: $puzzle500_01->identificationNumber,
            originalImage: $puzzle500_01->image,
        );
        $manager->persist($pendingChange);
        $this->addReference(self::CHANGE_REQUEST_PENDING, $pendingChange);

        // Approved change request
        $approvedChange = new PuzzleChangeRequest(
            id: Uuid::fromString(self::CHANGE_REQUEST_APPROVED),
            puzzle: $puzzle500_02,
            reporter: $regularPlayer,
            submittedAt: $now->modify('-1 day'),
            proposedName: 'Already Approved Name',
            proposedManufacturer: null,
            proposedPiecesCount: 600,
            proposedEan: null,
            proposedIdentificationNumber: null,
            proposedImage: null,
            originalName: $puzzle500_02->name,
            originalManufacturerId: $puzzle500_02->manufacturer?->id,
            originalPiecesCount: $puzzle500_02->piecesCount,
            originalEan: $puzzle500_02->ean,
            originalIdentificationNumber: $puzzle500_02->identificationNumber,
            originalImage: $puzzle500_02->image,
        );
        $approvedChange->approve($adminPlayer, $now);
        $manager->persist($approvedChange);
        $this->addReference(self::CHANGE_REQUEST_APPROVED, $approvedChange);

        // Rejected change request
        $rejectedChange = new PuzzleChangeRequest(
            id: Uuid::fromString(self::CHANGE_REQUEST_REJECTED),
            puzzle: $puzzle500_03,
            reporter: $regularPlayer,
            submittedAt: $now->modify('-2 days'),
            proposedName: 'Rejected Name',
            proposedManufacturer: null,
            proposedPiecesCount: null,
            proposedEan: null,
            proposedIdentificationNumber: null,
            proposedImage: null,
            originalName: $puzzle500_03->name,
            originalManufacturerId: $puzzle500_03->manufacturer?->id,
            originalPiecesCount: $puzzle500_03->piecesCount,
            originalEan: $puzzle500_03->ean,
            originalIdentificationNumber: $puzzle500_03->identificationNumber,
            originalImage: $puzzle500_03->image,
        );
        $rejectedChange->reject($adminPlayer, $now, 'Not a valid change');
        $manager->persist($rejectedChange);
        $this->addReference(self::CHANGE_REQUEST_REJECTED, $rejectedChange);

        // Pending change request with proposed image
        $changeWithImage = new PuzzleChangeRequest(
            id: Uuid::fromString(self::CHANGE_REQUEST_WITH_IMAGE),
            puzzle: $puzzle500_02,
            reporter: $regularPlayer,
            submittedAt: $now,
            proposedName: 'New Image Puzzle',
            proposedManufacturer: $ravensburger,
            proposedPiecesCount: 500,
            proposedEan: null,
            proposedIdentificationNumber: null,
            proposedImage: 'proposal-' . self::CHANGE_REQUEST_WITH_IMAGE . '.jpg',
            originalName: $puzzle500_02->name,
            originalManufacturerId: $puzzle500_02->manufacturer?->id,
            originalPiecesCount: $puzzle500_02->piecesCount,
            originalEan: $puzzle500_02->ean,
            originalIdentificationNumber: $puzzle500_02->identificationNumber,
            originalImage: $puzzle500_02->image,
        );
        $manager->persist($changeWithImage);
        $this->addReference(self::CHANGE_REQUEST_WITH_IMAGE, $changeWithImage);

        // Pending merge request
        $pendingMerge = new PuzzleMergeRequest(
            id: Uuid::fromString(self::MERGE_REQUEST_PENDING),
            sourcePuzzle: $puzzle500_01,
            reporter: $regularPlayer,
            submittedAt: $now,
            reportedDuplicatePuzzleIds: [
                PuzzleFixture::PUZZLE_500_01,
                PuzzleFixture::PUZZLE_500_02,
            ],
        );
        $manager->persist($pendingMerge);
        $this->addReference(self::MERGE_REQUEST_PENDING, $pendingMerge);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            ManufacturerFixture::class,
            PuzzleFixture::class,
        ];
    }
}
