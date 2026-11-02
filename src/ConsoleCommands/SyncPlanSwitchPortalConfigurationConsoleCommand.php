<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SyncPlanSwitchPortalConfiguration;
use SpeedPuzzling\Web\Results\PlanSwitchPortalConfigurationSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:stripe:sync-plan-switch-portal',
    description: 'Create or update the Stripe portal configuration for the monthly <-> yearly switch (manual, once per settings change)',
)]
final class SyncPlanSwitchPortalConfigurationConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be sent to Stripe, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $envelope = $this->messageBus->dispatch(new SyncPlanSwitchPortalConfiguration($dryRun));

        $result = $envelope->last(HandledStamp::class)?->getResult();
        assert($result instanceof PlanSwitchPortalConfigurationSync);

        $io->writeln((string) json_encode($result->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result->dryRun) {
            $io->note($result->created ? 'Dry run - would create a new configuration.' : sprintf('Dry run - would update %s.', $result->configurationId));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s %s. Set STRIPE_PLAN_SWITCH_PORTAL_CONFIGURATION=%s',
            $result->created ? 'Created' : 'Updated',
            $result->configurationId,
            $result->configurationId,
        ));

        return Command::SUCCESS;
    }
}
