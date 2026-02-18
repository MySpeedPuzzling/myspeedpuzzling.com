<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\RemovePuzzleFromSellSwapList;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RemovePuzzleFromSellSwapListHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetSellSwapListItems $getSellSwapListItems;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getSellSwapListItems = $container->get(GetSellSwapListItems::class);
    }

    public function testRemovingExistingItem(): void
    {
        // SELLSWAP_02 belongs to PLAYER_WITH_STRIPE, puzzle 500_02
        $countBefore = $this->getSellSwapListItems->countByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertTrue($this->getSellSwapListItems->isPuzzleInSellSwapList(
            PlayerFixture::PLAYER_WITH_STRIPE,
            PuzzleFixture::PUZZLE_500_02,
        ));

        $this->messageBus->dispatch(
            new RemovePuzzleFromSellSwapList(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                puzzleId: PuzzleFixture::PUZZLE_500_02,
            ),
        );

        $countAfter = $this->getSellSwapListItems->countByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame($countBefore - 1, $countAfter);

        self::assertFalse($this->getSellSwapListItems->isPuzzleInSellSwapList(
            PlayerFixture::PLAYER_WITH_STRIPE,
            PuzzleFixture::PUZZLE_500_02,
        ));
    }

    public function testRemovingNonExistentItemIsIdempotent(): void
    {
        // PUZZLE_1000_05 is NOT in PLAYER_WITH_STRIPE's sell/swap list
        $countBefore = $this->getSellSwapListItems->countByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        $this->messageBus->dispatch(
            new RemovePuzzleFromSellSwapList(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                puzzleId: PuzzleFixture::PUZZLE_1000_05,
            ),
        );

        $countAfter = $this->getSellSwapListItems->countByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame($countBefore, $countAfter);
    }
}
