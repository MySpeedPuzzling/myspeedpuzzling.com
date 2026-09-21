<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\EditPlayerCode;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every change of a player's code leaves a row in player_code_change - who held which code, and when.
 */
final class EditPlayerCodeHandlerLogTest extends KernelTestCase
{
    public function testEveryChangeIsLoggedWithTheCodeBeforeAndAfter(): void
    {
        $container = self::getContainer();
        $messageBus = $container->get(MessageBusInterface::class);
        $original = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE)->code;

        $messageBus->dispatch(new EditPlayerCode(PlayerFixture::PLAYER_WITH_STRIPE, 'FirstPick'));
        $messageBus->dispatch(new EditPlayerCode(PlayerFixture::PLAYER_WITH_STRIPE, 'secondpick'));

        self::assertSame(
            [
                ['previous_code' => $original, 'new_code' => 'firstpick'],
                ['previous_code' => 'firstpick', 'new_code' => 'secondpick'],
            ],
            $this->history(PlayerFixture::PLAYER_WITH_STRIPE),
        );
    }

    public function testSavingTheSameCodeAgainIsNotAChange(): void
    {
        $container = self::getContainer();
        $code = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE)->code;

        $container->get(MessageBusInterface::class)->dispatch(new EditPlayerCode(PlayerFixture::PLAYER_WITH_STRIPE, strtoupper($code)));

        self::assertSame([], $this->history(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(string $playerId): array
    {
        return self::getContainer()->get(Connection::class)
            ->executeQuery('SELECT previous_code, new_code FROM player_code_change WHERE player_id = :id ORDER BY changed_at, id', ['id' => $playerId])
            ->fetchAllAssociative();
    }
}
