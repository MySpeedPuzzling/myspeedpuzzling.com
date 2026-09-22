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

final class PuzzleFixture extends Fixture implements DependentFixtureInterface
{
    public const string PUZZLE_500_01 = '018d0003-0000-0000-0000-000000000001';
    public const string PUZZLE_500_02 = '018d0003-0000-0000-0000-000000000002';
    public const string PUZZLE_500_03 = '018d0003-0000-0000-0000-000000000003';
    public const string PUZZLE_500_04 = '018d0003-0000-0000-0000-000000000004';
    public const string PUZZLE_500_05 = '018d0003-0000-0000-0000-000000000005';
    public const string PUZZLE_1000_01 = '018d0003-0000-0000-0000-000000000006';
    public const string PUZZLE_1000_02 = '018d0003-0000-0000-0000-000000000007';
    public const string PUZZLE_1000_03 = '018d0003-0000-0000-0000-000000000008';
    public const string PUZZLE_1000_04 = '018d0003-0000-0000-0000-000000000009';
    public const string PUZZLE_1000_05 = '018d0003-0000-0000-0000-000000000010';
    public const string PUZZLE_300 = '018d0003-0000-0000-0000-000000000011';
    public const string PUZZLE_1500_01 = '018d0003-0000-0000-0000-000000000012';
    public const string PUZZLE_1500_02 = '018d0003-0000-0000-0000-000000000013';
    public const string PUZZLE_2000 = '018d0003-0000-0000-0000-000000000014';
    public const string PUZZLE_3000 = '018d0003-0000-0000-0000-000000000015';
    public const string PUZZLE_4000 = '018d0003-0000-0000-0000-000000000016';
    public const string PUZZLE_5000 = '018d0003-0000-0000-0000-000000000017';
    public const string PUZZLE_6000 = '018d0003-0000-0000-0000-000000000018';
    public const string PUZZLE_9000 = '018d0003-0000-0000-0000-000000000019';
    public const string PUZZLE_UNAPPROVED = '018d0003-0000-0000-0000-000000000020';
    public const string PUZZLE_HIDDEN_IMAGE = '018d0003-0000-0000-0000-000000000021';

    // Valid GS1 codes for the multiscan tests (the two codes on PUZZLE_500_02 / PUZZLE_1000_03 have a wrong check digit)
    public const string EAN_PUZZLE_300 = '4005556202027';
    public const string EAN_PUZZLE_500_03 = '4005556777778';
    public const string EAN_PUZZLE_1500_01 = '4005556404049';
    public const string EAN_PUZZLE_1500_02 = '5900511101010';
    public const string EAN_PUZZLE_2000 = '4005556123452';
    public const string EAN_PUZZLE_3000 = '5900511303032';
    public const string EAN_SHARED_4000_5000 = '4005556999996';
    public const string EAN_PUZZLE_6000 = '5900511505054';
    public const string EAN_UNKNOWN = '4005556555550';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $ravensburger = $this->getReference(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, Manufacturer::class);
        $trefl = $this->getReference(ManufacturerFixture::MANUFACTURER_TREFL, Manufacturer::class);
        $unapproved = $this->getReference(ManufacturerFixture::MANUFACTURER_UNAPPROVED, Manufacturer::class);
        $adminPlayer = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $regularPlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        // 5x 500 pieces
        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_500_01,
            name: 'Puzzle 1',
            piecesCount: 500,
            manufacturer: $ravensburger,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
            identificationNumber: 'RB-500-001',
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_500_01, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_500_02,
            name: 'Puzzle 2',
            piecesCount: 500,
            manufacturer: $ravensburger,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
            ean: '4005556123456',
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_500_02, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_500_03,
            name: 'Puzzle 3',
            piecesCount: 500,
            manufacturer: $ravensburger,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
            ean: self::EAN_PUZZLE_500_03,
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_500_03, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_500_04,
            name: 'Puzzle 4',
            piecesCount: 500,
            manufacturer: $trefl,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_500_04, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_500_05,
            name: 'Puzzle 5',
            piecesCount: 500,
            manufacturer: $trefl,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: false,
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_500_05, $puzzle);

        // 5x 1000 pieces
        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_1000_01,
            name: 'Puzzle 6',
            piecesCount: 1000,
            manufacturer: $ravensburger,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
            identificationNumber: 'RB-1000-001',
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_1000_01, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_1000_02,
            name: 'Puzzle 7',
            piecesCount: 1000,
            manufacturer: $trefl,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_1000_02, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_1000_03,
            name: 'Puzzle 8',
            piecesCount: 1000,
            manufacturer: $ravensburger,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
            ean: '4005556789012',
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_1000_03, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_1000_04,
            name: 'Puzzle 9',
            piecesCount: 1000,
            manufacturer: $trefl,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_1000_04, $puzzle);

        $puzzle = $this->createPuzzle(
            id: self::PUZZLE_1000_05,
            name: 'Puzzle 10',
            piecesCount: 1000,
            manufacturer: $ravensburger,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_1000_05, $puzzle);

        // Various piece counts
        // EANs below carry a valid GS1 check digit (multiscan validates codes); PUZZLE_4000 and
        // PUZZLE_5000 deliberately share one (ambiguous scan), PUZZLE_9000 has none (linking tests)
        $variousPuzzles = [
            ['id' => self::PUZZLE_300, 'name' => 'Puzzle 11', 'pieces' => 300, 'manufacturer' => $ravensburger, 'ean' => self::EAN_PUZZLE_300],
            ['id' => self::PUZZLE_1500_01, 'name' => 'Puzzle 12', 'pieces' => 1500, 'manufacturer' => $ravensburger, 'ean' => self::EAN_PUZZLE_1500_01],
            ['id' => self::PUZZLE_1500_02, 'name' => 'Puzzle 13', 'pieces' => 1500, 'manufacturer' => $trefl, 'ean' => self::EAN_PUZZLE_1500_02],
            ['id' => self::PUZZLE_2000, 'name' => 'Puzzle 14', 'pieces' => 2000, 'manufacturer' => $ravensburger, 'ean' => self::EAN_PUZZLE_2000],
            ['id' => self::PUZZLE_3000, 'name' => 'Puzzle 15', 'pieces' => 3000, 'manufacturer' => $trefl, 'ean' => self::EAN_PUZZLE_3000],
            ['id' => self::PUZZLE_4000, 'name' => 'Puzzle 16', 'pieces' => 4000, 'manufacturer' => $ravensburger, 'ean' => self::EAN_SHARED_4000_5000],
            ['id' => self::PUZZLE_5000, 'name' => 'Puzzle 17', 'pieces' => 5000, 'manufacturer' => $ravensburger, 'ean' => self::EAN_SHARED_4000_5000],
            ['id' => self::PUZZLE_6000, 'name' => 'Puzzle 18', 'pieces' => 6000, 'manufacturer' => $trefl, 'ean' => self::EAN_PUZZLE_6000],
            ['id' => self::PUZZLE_9000, 'name' => 'Puzzle 19', 'pieces' => 9000, 'manufacturer' => $ravensburger, 'ean' => null],
        ];

        foreach ($variousPuzzles as $data) {
            $puzzle = $this->createPuzzle(
                id: $data['id'],
                name: $data['name'],
                piecesCount: $data['pieces'],
                manufacturer: $data['manufacturer'],
                addedByUser: $adminPlayer,
                approved: true,
                isAvailable: true,
                ean: $data['ean'],
            );
            $manager->persist($puzzle);
            $this->addReference($data['id'], $puzzle);
        }

        // Unapproved puzzle
        $unapprovedPuzzle = $this->createPuzzle(
            id: self::PUZZLE_UNAPPROVED,
            name: 'Puzzle 20',
            piecesCount: 1000,
            manufacturer: $unapproved,
            addedByUser: $regularPlayer,
            approved: false,
            isAvailable: false,
        );
        $manager->persist($unapprovedPuzzle);
        $this->addReference(self::PUZZLE_UNAPPROVED, $unapprovedPuzzle);

        // Puzzle with hidden image (unreleased)
        $hiddenImagePuzzle = $this->createPuzzle(
            id: self::PUZZLE_HIDDEN_IMAGE,
            name: 'Puzzle Hidden Image',
            piecesCount: 1000,
            manufacturer: $ravensburger,
            addedByUser: $adminPlayer,
            approved: true,
            isAvailable: true,
            hideImageUntil: new \DateTimeImmutable('2099-12-31'),
        );
        $manager->persist($hiddenImagePuzzle);
        $this->addReference(self::PUZZLE_HIDDEN_IMAGE, $hiddenImagePuzzle);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            ManufacturerFixture::class,
        ];
    }

    private function createPuzzle(
        string $id,
        string $name,
        int $piecesCount,
        Manufacturer $manufacturer,
        Player $addedByUser,
        bool $approved,
        bool $isAvailable = false,
        null|string $identificationNumber = null,
        null|string $ean = null,
        null|string $alternativeName = null,
        null|\DateTimeImmutable $hideImageUntil = null,
    ): Puzzle {
        return new Puzzle(
            id: Uuid::fromString($id),
            piecesCount: $piecesCount,
            name: $name,
            approved: $approved,
            image: null,
            manufacturer: $manufacturer,
            alternativeName: $alternativeName,
            addedByUser: $addedByUser,
            addedAt: $this->clock->now(),
            identificationNumber: $identificationNumber,
            ean: $ean,
            isAvailable: $isAvailable,
            hideImageUntil: $hideImageUntil,
        );
    }
}
