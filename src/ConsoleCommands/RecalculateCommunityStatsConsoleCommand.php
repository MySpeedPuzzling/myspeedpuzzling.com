<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:recalculate-community-stats',
    description: 'Rebuild the Players page numbers (community_player_stats, community_scope_stats) and detect player moments',
)]
final class RecalculateCommunityStatsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $envelope = $this->messageBus->dispatch(new RecalculateCommunityStats());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{players: int, scopes: int, moments_detected: int, moments_written: int, moments_removed: int} $result */
        $result = $handledStamp->getResult();

        $io->success(sprintf(
            'Community stats rebuilt in %.2f s: %d player rows changed, %d scopes; moments: %d detected, %d written, %d removed.',
            microtime(true) - $started,
            $result['players'],
            $result['scopes'],
            $result['moments_detected'],
            $result['moments_written'],
            $result['moments_removed'],
        ));

        return Command::SUCCESS;
    }
}
