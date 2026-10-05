<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/brand-duplicates.md - a brand typed as text is the existing brand of that name
 * (case and spacing ignored), approved or not, whoever added it; only otherwise a new brand.
 */
final class AddPuzzleBrandTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testATypedNameIsTheExistingBrandIgnoringCaseAndSpacing(): void
    {
        $brandsBefore = $this->countBrands();

        $puzzleId = $this->addPuzzle("  trefl \t");

        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $this->brandOf($puzzleId));
        self::assertSame($brandsBefore, $this->countBrands());
    }

    public function testATypedNameIsAnotherPlayersUnapprovedBrand(): void
    {
        // "Unknown Brand" is unapproved and was added by PLAYER_REGULAR
        $puzzleId = $this->addPuzzle('UNKNOWN   brand', PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);

        self::assertSame(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $this->brandOf($puzzleId));
    }

    public function testANameNoBrandHasIsANewUnapprovedBrandStoredTidy(): void
    {
        $brandsBefore = $this->countBrands();

        $puzzleId = $this->addPuzzle('  Brand   Nobody Has ');

        /** @var array{name: string, approved: bool, slug: string} $brand */
        $brand = $this->database->fetchAssociative(
            'SELECT manufacturer.name, manufacturer.approved, manufacturer.slug FROM manufacturer JOIN puzzle ON puzzle.manufacturer_id = manufacturer.id WHERE puzzle.id = :id',
            ['id' => $puzzleId->toString()],
        );

        self::assertSame($brandsBefore + 1, $this->countBrands());
        self::assertSame('Brand Nobody Has', $brand['name']);
        self::assertFalse($brand['approved']);
        self::assertSame('brand-nobody-has', $brand['slug']);
    }

    public function testOnlyCaseAndSpacingAreIgnored(): void
    {
        $puzzleId = $this->addPuzzle('Tref-l');

        self::assertNotSame(ManufacturerFixture::MANUFACTURER_TREFL, $this->brandOf($puzzleId));
    }

    public function testAmongSeveralBrandsOfTheNameTheApprovedOneWins(): void
    {
        // An unapproved copy, older than the approved original
        $copyId = $this->createBrand('TREFL', new DateTimeImmutable('2020-01-01'));

        $puzzleId = $this->addPuzzle('trefl');

        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $this->brandOf($puzzleId));
        self::assertNotSame($copyId->toString(), $this->brandOf($puzzleId));
    }

    public function testAmongUnapprovedBrandsTheOneWithMorePuzzlesWinsThenTheOldest(): void
    {
        $older = $this->createBrand('Twin Brand', new DateTimeImmutable('2024-01-01'));
        $newer = $this->createBrand('twin brand', new DateTimeImmutable('2025-01-01'));

        self::assertSame($older->toString(), $this->brandOf($this->addPuzzle('Twin brand')));

        // The newer copy gets two puzzles (by id), the older one has one now
        $this->addPuzzle($newer->toString());
        $this->addPuzzle($newer->toString());

        self::assertSame($newer->toString(), $this->brandOf($this->addPuzzle('TWIN BRAND')));
    }

    public function testTheCorrectedNewPuzzleKeepsTheBrandItsFirstSubmitCreated(): void
    {
        $puzzleId = Uuid::uuid7();
        $this->addPuzzle('Fresh Brand', puzzleId: $puzzleId, piecesCount: 5000);
        $firstBrand = $this->brandOf($puzzleId);

        $this->addPuzzle(' fresh  brand', puzzleId: $puzzleId, piecesCount: 500);

        self::assertSame($firstBrand, $this->brandOf($puzzleId));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM manufacturer WHERE LOWER(name) = 'fresh brand'"));
    }

    private function addPuzzle(
        string $brand,
        string $userId = PlayerFixture::PLAYER_REGULAR_USER_ID,
        null|UuidInterface $puzzleId = null,
        int $piecesCount = 1000,
    ): UuidInterface {
        $puzzleId ??= Uuid::uuid7();

        $imagePath = tempnam(sys_get_temp_dir(), 'puzzle_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $this->messageBus->dispatch(new AddPuzzle(
            puzzleId: $puzzleId,
            userId: $userId,
            puzzleName: 'Brand test puzzle',
            brand: $brand,
            piecesCount: $piecesCount,
            puzzlePhoto: new UploadedFile($imagePath, 'box.jpg', 'image/jpeg', null, true),
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
        ));

        return $puzzleId;
    }

    private function createBrand(string $name, DateTimeImmutable $addedAt): UuidInterface
    {
        $id = Uuid::uuid7();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Manufacturer($id, $name, false, null, $addedAt));
        $entityManager->flush();

        return $id;
    }

    private function brandOf(UuidInterface $puzzleId): string
    {
        $brandId = $this->database->fetchOne('SELECT manufacturer_id FROM puzzle WHERE id = :id', ['id' => $puzzleId->toString()]);
        assert(is_string($brandId));

        return $brandId;
    }

    private function countBrands(): int
    {
        /** @var int|string $count */
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM manufacturer');

        return (int) $count;
    }
}
