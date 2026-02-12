<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Message\AddPuzzleToSellSwapList;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddPuzzleToSellSwapListHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testBannedUserCannotCreateListing(): void
    {
        // Ban the player first
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->banFromMarketplace();

        try {
            $this->messageBus->dispatch(
                new AddPuzzleToSellSwapList(
                    playerId: PlayerFixture::PLAYER_REGULAR,
                    puzzleId: PuzzleFixture::PUZZLE_1000_05,
                    listingType: ListingType::Sell,
                    price: 100.0,
                    condition: PuzzleCondition::LikeNew,
                    comment: 'Should not work - user is banned',
                ),
            );
            self::fail('Expected MarketplaceBanned exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MarketplaceBanned::class, $previous);
        }
    }
}
