<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Message\AddApprovedPuzzle;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddApprovedPuzzleHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleRepository $puzzleRepository;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
        $this->database = $container->get(Connection::class);
    }

    public function testAddsAnApprovedPuzzleWithoutAPhoto(): void
    {
        $puzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddApprovedPuzzle(
            puzzleId: $puzzleId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Kingdom of Colours',
            brand: ManufacturerFixture::MANUFACTURER_TREFL,
            piecesCount: 1000,
            eans: EanList::fromInputs(['4006381333931']),
            brandCodes: BrandCodeList::fromInputs(['10763']),
            nameLanguage: null,
            alternativeNames: new PuzzleNames([new PuzzleName('Království barev', 'cs')]),
        ));

        $puzzle = $this->puzzleRepository->get($puzzleId->toString());
        self::assertTrue($puzzle->approved);
        self::assertNull($puzzle->image);
        self::assertSame('Kingdom of Colours', $puzzle->name);
        self::assertSame(1000, $puzzle->piecesCount);
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $puzzle->manufacturer?->id->toString());
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $puzzle->addedByUser?->id->toString());
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $puzzle->approvedBy?->id->toString());
        self::assertSame(['4006381333931'], $puzzle->eans()->codes());
        self::assertSame(['10763'], $puzzle->brandCodes()->codes());
        self::assertSame([['name' => 'Království barev', 'language' => 'cs']], $puzzle->alternativeNames()->toArray());

        $decision = $this->database->fetchAssociative(
            'SELECT action, source, decided_by_id FROM puzzle_moderation_decision WHERE puzzle_id = :id',
            ['id' => $puzzleId->toString()],
        );
        self::assertSame(['action' => 'puzzle_approved', 'source' => 'internal_api', 'decided_by_id' => PlayerFixture::PLAYER_ADMIN], $decision);
    }

    public function testATypedBrandIsFoundOrCreated(): void
    {
        $existing = Uuid::uuid7();
        $this->messageBus->dispatch($this->add($existing, '  trefl '));
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $this->puzzleRepository->get($existing->toString())->manufacturer?->id->toString());

        $new = Uuid::uuid7();
        $this->messageBus->dispatch($this->add($new, 'Pusselbolaget'));
        $brand = $this->puzzleRepository->get($new->toString())->manufacturer;
        self::assertNotNull($brand);
        self::assertSame('Pusselbolaget', $brand->name);
        self::assertFalse($brand->approved);
    }

    public function testAnUnknownBrandIdIsNotFound(): void
    {
        $this->expectException(ManufacturerNotFound::class);

        $this->messageBus->dispatch($this->add(Uuid::uuid7(), '018d0002-0000-0000-0000-00000000ffff'));
    }

    private function add(\Ramsey\Uuid\UuidInterface $puzzleId, string $brand): AddApprovedPuzzle
    {
        return new AddApprovedPuzzle(
            puzzleId: $puzzleId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Typed Brand Puzzle',
            brand: $brand,
            piecesCount: 500,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
        );
    }
}
