<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\MarkListingAsReserved;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarkListingAsReservedHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private SellSwapListItemRepository $sellSwapListItemRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->sellSwapListItemRepository = $container->get(SellSwapListItemRepository::class);
    }

    public function testMarkingItemAsReservedSucceeds(): void
    {
        // SELLSWAP_01 belongs to PLAYER_WITH_STRIPE and is not reserved
        $this->messageBus->dispatch(
            new MarkListingAsReserved(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_01,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            ),
        );

        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_01);
        self::assertTrue($item->reserved);
        self::assertNotNull($item->reservedAt);
        self::assertNull($item->reservedForPlayerId);
    }

    public function testMarkingItemAsReservedWithReservedForPlayerId(): void
    {
        $this->messageBus->dispatch(
            new MarkListingAsReserved(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_02,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                reservedForPlayerId: PlayerFixture::PLAYER_ADMIN,
            ),
        );

        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_02);
        self::assertTrue($item->reserved);
        self::assertNotNull($item->reservedAt);
        self::assertNotNull($item->reservedForPlayerId);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $item->reservedForPlayerId->toString());
    }

    public function testNonOwnerCannotMarkAsReserved(): void
    {
        try {
            $this->messageBus->dispatch(
                new MarkListingAsReserved(
                    sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_01,
                    playerId: PlayerFixture::PLAYER_ADMIN,
                ),
            );
            self::fail('Expected SellSwapListItemNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(SellSwapListItemNotFound::class, $previous);
        }
    }
}
