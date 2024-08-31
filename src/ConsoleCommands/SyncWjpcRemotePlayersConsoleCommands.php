<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\UpdateWjpcPlayerId;
use SpeedPuzzling\Web\Query\GetWjpcParticipants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('myspeedpuzzling:wjpc:sync-player-ids')]
final class SyncWjpcRemotePlayersConsoleCommands extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetWjpcParticipants $getWjpcParticipants,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('participantId', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var null|string $participantId */
        $participantId = $input->getArgument('participantId');

        if (is_string($participantId)){
            $this->messageBus->dispatch(
                new UpdateWjpcPlayerId($participantId),
            );
        }

        if ($participantId === null) {
            $connectedParticipants = $this->getWjpcParticipants->getConnectedParticipants();

            foreach ($connectedParticipants as $participant) {
                $this->messageBus->dispatch(
                    new UpdateWjpcPlayerId($participant->participantId),
                );
            }
        }

        return self::SUCCESS;
    }
}
