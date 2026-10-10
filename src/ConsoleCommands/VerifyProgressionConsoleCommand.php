<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Services\Progression\ProgressionSafetyNet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'myspeedpuzzling:verify-progression',
    description: 'Find players whose XP ledger, level or Achievement Points disagree with their current records; --repair rebuilds them.',
)]
final class VerifyProgressionConsoleCommand extends Command
{
    public function __construct(
        readonly private ProgressionSafetyNet $progressionSafetyNet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('repair', null, InputOption::VALUE_NONE, 'Rebuild the players found (full XP rebuild / achievement evaluation, in this process).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $run = $this->progressionSafetyNet->check($input->getOption('repair') === true);

        $io->table(['Check', 'Players'], array_map(
            static fn (string $check, int $players): array => [$check, $players],
            array_keys($run->drift->counts()),
            array_values($run->drift->counts()),
        ));

        if ($run->drift->isClean()) {
            $io->success(sprintf('No drift (%.1f s).', microtime(true) - $started));

            return self::SUCCESS;
        }

        if ($run->failed !== []) {
            $io->error(sprintf('%d repair(s) failed.', count($run->failed)));
            $io->listing(array_slice($run->failed, 0, 50));

            return self::FAILURE;
        }

        $io->warning(sprintf('%d repair(s) done (%.1f s).', $run->repaired, microtime(true) - $started));

        return self::SUCCESS;
    }
}
