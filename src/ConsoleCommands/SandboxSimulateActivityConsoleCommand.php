<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxResultPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the sandbox alive (docs/features/sandbox/README.md "Data"): a random made-up player logs a time within the
 * usual range for a random catalogue puzzle, through the add-time form's own message - the Hub, feeds, rankings, API
 * results and insights move like they do on the real site. Cron on the sandbox only; refuses anywhere else.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sandbox:simulate-activity',
    description: 'Log a few fresh results of the sandbox\'s made-up players (SANDBOX=1 only).',
)]
final class SandboxSimulateActivityConsoleCommand extends Command
{
    public function __construct(
        readonly private SandboxMode $sandboxMode,
        readonly private MessageBusInterface $messageBus,
        readonly private GetSandboxSimulationData $simulationData,
        readonly private SandboxResultPlanner $planner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('results', null, InputOption::VALUE_REQUIRED, 'How many results to log', '2')
            ->addOption('pool', null, InputOption::VALUE_REQUIRED, 'How many catalogue puzzles they solve (the seed\'s pool)', '3000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sandboxMode->assertOn();

        $plan = $this->planner->recent(
            $this->simulationData->players(),
            $this->simulationData->puzzlePool(SandboxSeedConsoleCommand::intOption($input, 'pool')),
            fn (string $playerId): array => $this->simulationData->solvedCounts($playerId),
            SandboxSeedConsoleCommand::intOption($input, 'results'),
        );

        $refused = 0;

        foreach ($plan as $result) {
            try {
                $this->messageBus->dispatch(SandboxSeedConsoleCommand::message($result));
                $output->writeln(sprintf('%s logged %s on %s', $result->player->code, $result->timeInput(), $result->puzzle->id), OutputInterface::VERBOSITY_VERBOSE);
            } catch (\Throwable $e) {
                // A refused result (a rule of the add-time form) is no failure of the cron - the next run draws again
                $refused++;
                $output->writeln(sprintf('Refused: %s', $e->getMessage()));
            }
        }

        $output->writeln(sprintf('Logged %d, refused %d', count($plan) - $refused, $refused));

        return self::SUCCESS;
    }
}
