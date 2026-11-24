<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Services\Sandbox\SandboxDemoEventDefinitions;
use SpeedPuzzling\Web\Services\Sandbox\SandboxDemoEvents;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The sandbox's demo events for organisers (docs/features/sandbox/README.md "Demo events"): a World-Series-like
 * championship in Toronto and a winter-carnival-like weekend in Saint Paul, with made-up people and results; on request
 * (--event) the Speed Puzzle World Series format 1:1 (spws-demo-event.md) and the St. Paul Winter Carnival 2027 as we
 * guess it (README "Demo events"). Sandbox
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
            ->addOption('referee', null, InputOption::VALUE_REQUIRED, 'E-mail of the made-up referee, empty = none', 'test-user+2@myspeedpuzzling.com')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, sprintf('Build only the event of this slug (%s); without it the default ones', implode(', ', array_map(static fn ($event): string => $event->slug, SandboxDemoEventDefinitions::known()))))
            ->addOption('maintainer', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'E-mail of another organiser of a new event and of its organization (repeatable; needs --event)')
            ->addOption('logo', null, InputOption::VALUE_REQUIRED, 'Path to the logo of a new event and its new organization (a PNG/JPEG on this machine - never in the repository; needs --event)');
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
        $organizer = is_string($organizer) ? $organizer : '';
        $organizerPlayerId = $this->simulationData->playerIdByEmail($organizer);

        if ($organizerPlayerId === null) {
            $io->error('The organiser has no account - run myspeedpuzzling:sandbox:seed first (or pass --organizer).');

            return self::FAILURE;
        }

        $referee = $input->getOption('referee');
        $referee = is_string($referee) ? $referee : '';
        $refereePlayerId = $referee !== '' ? $this->simulationData->playerIdByEmail($referee) : null;

        $event = $input->getOption('event');
        $event = is_string($event) && $event !== '' ? $event : null;
        $knownSlugs = array_map(static fn ($definition): string => $definition->slug, SandboxDemoEventDefinitions::known());

        if ($event !== null && !in_array($event, $knownSlugs, true)) {
            $io->error(sprintf('--event must be one of: %s', implode(', ', $knownSlugs)));

            return self::FAILURE;
        }

        $maintainerEmails = $input->getOption('maintainer');
        $maintainerEmails = is_array($maintainerEmails) ? array_values(array_filter($maintainerEmails, is_string(...))) : [];
        $logo = $input->getOption('logo');
        $logo = is_string($logo) && $logo !== '' ? $logo : null;

        if (($maintainerEmails !== [] || $logo !== null) && $event === null) {
            $io->error('--maintainer and --logo shape one event - name it with --event.');

            return self::FAILURE;
        }

        if ($logo !== null && (!is_file($logo) || !is_readable($logo) || @getimagesize($logo) === false)) {
            $io->error(sprintf('--logo %s is no readable image.', $logo));

            return self::FAILURE;
        }

        $maintainerIds = [];

        foreach ($maintainerEmails as $email) {
            $playerId = $this->simulationData->playerIdByEmail($email);

            if ($playerId === null) {
                $io->error(sprintf('No account with the e-mail %s on this installation.', $email));

                return self::FAILURE;
            }

            $maintainerIds[] = $playerId;
        }

        $this->demoEvents->setUp($date, $organizerPlayerId, $refereePlayerId, static function (string $line) use ($io): void {
            $io->writeln($line);
        }, $event, $maintainerIds, $logo);

        $io->success(sprintf('Demo events ready. Organiser: %s (password "sandbox")%s.', $organizer, $refereePlayerId !== null ? ', referee: ' . $referee : ''));

        return self::SUCCESS;
    }
}
