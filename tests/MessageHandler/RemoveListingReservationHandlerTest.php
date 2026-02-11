<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\RemoveListingReservation;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class RemoveListingReservationHandlerTest extends KernelTestCase
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

    public function testRemovingReservationSucceeds(): void
    {
        // SELLSWAP_03 belongs to PLAYER_WITH_STRIPE and is reserved (set in fixture)
        $this->messageBus->dispatch(
            new RemoveListingReservation(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_03,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            ),
        );

        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_03);
        self::assertFalse($item->reserved);
        self::assertNull($item->reservedAt);
        self::assertNull($item->reservedForPlayerId);
    }

    public function testNonOwnerCannotRemoveReservation(): void
    {
        try {
            $this->messageBus->dispatch(
                new RemoveListingReservation(
                    sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_03,
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
