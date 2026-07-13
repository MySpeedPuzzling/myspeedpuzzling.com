<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'myspeedpuzzling:recalculate-xp',
    description: 'Deterministically rebuild the XP ledger for one player (--player=UUID) or all players with solves (--all).',
)]
final class RecalculateXpConsoleCommand extends Command
{
    public function __construct(
        readonly private GetAllPlayerIdsWithSolveTimes $getAllPlayerIds,
        readonly private MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'player',
                null,
                InputOption::VALUE_REQUIRED,
                'Recalculate only for this single player UUID.',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Recalculate for every player with solve times.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specificPlayer = $input->getOption('player');
        $all = (bool) $input->getOption('all');

        if ($specificPlayer !== null) {
            if (!is_string($specificPlayer)) {
                $io->error('Invalid --player value.');
                return self::INVALID;
            }

            $this->commandBus->dispatch(new RecalculateXpForPlayer($specificPlayer));
            $io->success("Dispatched XP recalculation for player {$specificPlayer}.");

            return self::SUCCESS;
        }

        if ($all === false) {
            $io->error('Pass either --player=UUID or --all.');

            return self::INVALID;
        }

        $playerIds = $this->getAllPlayerIds->execute();

        if ($playerIds === []) {
            $io->success('No players with solve times found — nothing to dispatch.');
            return self::SUCCESS;
        }

        foreach ($playerIds as $playerId) {
            $this->commandBus->dispatch(new RecalculateXpForPlayer($playerId));
        }

        $count = count($playerIds);
        $io->success("Dispatched {$count} XP recalculation message(s).");

        return self::SUCCESS;
    }
}
