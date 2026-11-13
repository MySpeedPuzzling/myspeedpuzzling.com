<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\CreateSandboxPlayers;
use SpeedPuzzling\Web\Message\GrantSandboxMembership;
use SpeedPuzzling\Web\Message\ImportSandboxCatalogue;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Everywhere but the sandbox (SANDBOX=0 - tests, production) the sandbox's writing messages refuse before they write.
 */
final class SandboxOnlyMessagesTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{object}>
     */
    public static function sandboxMessages(): iterable
    {
        yield 'players' => [new CreateSandboxPlayers(3, 'sandbox')];
        yield 'catalogue' => [new ImportSandboxCatalogue('/dev/null')];
        yield 'membership' => [new GrantSandboxMembership(PlayerFixture::PLAYER_REGULAR_USER_ID)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sandboxMessages')]
    public function testRefusedOutsideTheSandbox(object $message): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch($message);
            self::fail('The message was handled outside the sandbox');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(SandboxModeIsOff::class, $e->getPrevious());
        }
    }
}
