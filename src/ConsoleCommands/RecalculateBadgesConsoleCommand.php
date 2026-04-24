<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsCommand(
    name: 'myspeedpuzzling:recalculate-badges',
    description: 'Recalculate badges for one or all players by dispatching async recalc messages.',
)]
final class RecalculateBadgesConsoleCommand extends Command
{
    /**
     * Stagger between dispatched backfill messages — avoids flooding the mail transport
     * when many existing players become eligible in the same run.
     */
    private const int BACKFILL_DELAY_MS = 2000;

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
                'backfill',
                null,
                InputOption::VALUE_NONE,
                'Stagger dispatches with DelayStamp to spread out email delivery (initial seed runs).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specificPlayer = $input->getOption('player');
        $backfill = (bool) $input->getOption('backfill');

        if ($specificPlayer !== null) {
            if (!is_string($specificPlayer)) {
                $io->error('Invalid --player value.');
                return self::INVALID;
            }

            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($specificPlayer));
            $io->success("Dispatched badge recalculation for player {$specificPlayer}.");

            return self::SUCCESS;
        }

        $playerIds = $this->getAllPlayerIds->execute();

        if ($playerIds === []) {
            $io->success('No players with solve times found — nothing to dispatch.');
            return self::SUCCESS;
        }

        foreach ($playerIds as $index => $playerId) {
            $stamps = [];
            if ($backfill) {
                $stamps[] = new DelayStamp($index * self::BACKFILL_DELAY_MS);
            }

            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($playerId), $stamps);
        }

        $count = count($playerIds);
        $mode = $backfill ? 'backfill (staggered)' : 'immediate';
        $io->success("Dispatched {$count} badge recalculation message(s) — {$mode}.");

        return self::SUCCESS;
    }
}
