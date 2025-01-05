<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use SpeedPuzzling\Web\Message\GrantMembership;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('myspeedpuzzling:membership:grant')]
final class GrantMembershipToPlayerConsoleCommands extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('playerId', InputArgument::REQUIRED);
        $this->addArgument('endsAt', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $playerId */
        $playerId = $input->getArgument('playerId');

        /** @var string $endsAt */
        $endsAt = $input->getArgument('endsAt');

        $this->messageBus->dispatch(
            new GrantMembership(
                playerId: $playerId,
                endsAt: new DateTimeImmutable($endsAt),
            ),
        );

        return self::SUCCESS;
    }
}
