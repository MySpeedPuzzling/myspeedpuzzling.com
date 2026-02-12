<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\MuteUser;
use SpeedPuzzling\Web\Query\GetModerationActions;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ModerationActionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class MuteUserHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PlayerRepository $playerRepository;
    private GetModerationActions $getModerationActions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->getModerationActions = $container->get(GetModerationActions::class);
    }

    public function testMutingSetsCorrectFieldsOnPlayer(): void
    {
        $this->messageBus->dispatch(
            new MuteUser(
                targetPlayerId: PlayerFixture::PLAYER_REGULAR,
                adminId: PlayerFixture::PLAYER_ADMIN,
                days: 7,
                reason: 'Test mute',
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->messagingMuted);
        self::assertNotNull($player->messagingMutedUntil);
        self::assertTrue($player->isMessagingMuted());
    }

    public function testMuteCreatesModerationAction(): void
    {
        $this->messageBus->dispatch(
            new MuteUser(
                targetPlayerId: PlayerFixture::PLAYER_REGULAR,
                adminId: PlayerFixture::PLAYER_ADMIN,
                days: 14,
                reason: 'Test mute action',
            ),
        );

        $actions = $this->getModerationActions->forPlayer(PlayerFixture::PLAYER_REGULAR);
        $found = false;
        foreach ($actions as $action) {
            if ($action->actionType === ModerationActionType::TemporaryMute && $action->reason === 'Test mute action') {
                $found = true;
                self::assertNotNull($action->expiresAt);
                break;
            }
        }
        self::assertTrue($found, 'Mute moderation action should be recorded');
    }
}
