<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Services\Sandbox\SandboxDemoEvents;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The sandbox's two demo events for organisers (docs/features/sandbox/README.md "Demo events"): a World-Series-like
 * championship in Toronto and a winter-carnival-like weekend in Saint Paul, with made-up people and results. Sandbox
 * only (SANDBOX=1) - it refuses anywhere else before writing anything. Safe to run again: it completes what exists
 * and never duplicates or overwrites anything. The work is SandboxDemoEvents' - every write is a message.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sandbox:demo-events',
    description: 'Create or complete the sandbox\'s two demo events with made-up participants and results (SANDBOX=1 only).',
)]
final class SandboxDemoEventsConsoleCommand extends Command
{
    public function __construct(
        readonly private SandboxMode $sandboxMode,
        readonly private SandboxDemoEvents $demoEvents,
        readonly private GetSandboxSimulationData $simulationData,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'The demo day (YYYY-MM-DD): the first blocks happened the day before, the second ones run that day. Default: today in each event\'s time zone')
            ->addOption('organizer', null, InputOption::VALUE_REQUIRED, 'E-mail of the made-up organiser (signs in with the password "sandbox")', 'test-user+1@myspeedpuzzling.com')
            ->addOption('referee', null, InputOption::VALUE_REQUIRED, 'E-mail of the made-up referee, empty = none', 'test-user+2@myspeedpuzzling.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->sandboxMode->assertOn();

        $date = $input->getOption('date');

        if ($date !== null && (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false)) {
            $io->error('--date must be a day like 2026-12-02.');

            return self::FAILURE;
        }

        $organizer = $input->getOption('organizer');
        $organizerPlayerId = is_string($organizer) ? $this->simulationData->playerIdByEmail($organizer) : null;

        if ($organizerPlayerId === null) {
            $io->error('The organiser has no account - run myspeedpuzzling:sandbox:seed first (or pass --organizer).');

            return self::FAILURE;
        }

        $referee = $input->getOption('referee');
        $refereePlayerId = is_string($referee) && $referee !== '' ? $this->simulationData->playerIdByEmail($referee) : null;

        $this->demoEvents->setUp($date, $organizerPlayerId, $refereePlayerId, static function (string $line) use ($io): void {
            $io->writeln($line);
        });

        $io->success(sprintf('Demo events ready. Organiser: %s (password "sandbox")%s.', $organizer, $refereePlayerId !== null && is_string($referee) ? ', referee: ' . $referee : ''));

        return self::SUCCESS;
    }
}
