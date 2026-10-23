<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\FreezeXpDifficultyTiers;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'myspeedpuzzling:freeze-xp-difficulty-tiers',
    description: 'Freeze the difficulty tier every solve earned its XP with, read back from the ledger (one player with --player=UUID, or --all). Idempotent, changes no XP.',
)]
final class FreezeXpDifficultyTiersConsoleCommand extends Command
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
            ->addOption('player', null, InputOption::VALUE_REQUIRED, 'Only this player UUID.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every player with solve times.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specificPlayer = $input->getOption('player');

        if (is_string($specificPlayer)) {
            $playerIds = [$specificPlayer];
        } elseif ((bool) $input->getOption('all')) {
            $playerIds = $this->getAllPlayerIds->execute();
        } else {
            $io->error('Pass either --player=UUID or --all.');

            return self::INVALID;
        }

        $io->progressStart(count($playerIds));

        foreach ($playerIds as $playerId) {
            $this->commandBus->dispatch(new FreezeXpDifficultyTiers($playerId));
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf('Froze the difficulty tiers of %d player(s).', count($playerIds)));

        return self::SUCCESS;
    }
}
