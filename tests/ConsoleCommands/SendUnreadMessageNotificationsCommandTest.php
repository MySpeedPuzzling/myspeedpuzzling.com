<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SendUnreadMessageNotificationsCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('myspeedpuzzling:messages:notify-unread');
        $this->commandTester = new CommandTester($command);
    }

    public function testCommandRunsSuccessfully(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('notification', $this->commandTester->getDisplay());
    }

    public function testCommandSendsEmailsForPlayersWithUnreadMessages(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        // Should have sent at least 1 notification (for PLAYER_REGULAR who has old unread messages)
        self::assertStringContainsString('Sent', $output);
    }

    public function testCommandIsIdempotent(): void
    {
        // First run
        $this->commandTester->execute([]);
        self::assertSame(0, $this->commandTester->getStatusCode());

        // Second run should not send duplicate emails
        $this->commandTester->execute([]);
        $secondOutput = $this->commandTester->getDisplay();
        self::assertSame(0, $this->commandTester->getStatusCode());

        // Second run should send 0 notifications since all players were already notified
        self::assertStringContainsString('No players to notify', $secondOutput);
    }
}
