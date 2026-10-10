<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PlanContentDigestRuns;
use SpeedPuzzling\Web\Results\ContentDigestRunPlan;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:send-content-digest',
    description: 'Queue the activity digests (Monday, kind "activity") or the no-activity e-mails (Tuesday, kind "no-activity") of every frequency whose period is due, spaced over the day',
)]
final class SendContentDigestConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED, '"activity" (Mondays) or "no-activity" (the day after)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the recipients');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('kind');
        $kind = is_string($argument) ? ContentDigestKind::tryFrom(str_replace('-', '_', $argument)) : null;

        if ($kind === null) {
            $io->error('The kind is "activity" or "no-activity".');

            return self::INVALID;
        }

        $envelope = $this->messageBus->dispatch(new PlanContentDigestRuns($kind, dryRun: (bool) $input->getOption('dry-run')));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var list<ContentDigestRunPlan> $plans */
        $plans = $handledStamp->getResult();

        $io->table(
            ['Frequency', 'Period', 'Outcome', 'Recipients', 'Spacing'],
            array_map(static fn (ContentDigestRunPlan $plan): array => [
                $plan->frequency->value,
                $plan->periodKey,
                $plan->outcome,
                $plan->recipients,
                $plan->spacingMs > 0 ? sprintf('%.1f s', $plan->spacingMs / 1000) : '',
            ], $plans),
        );

        return self::SUCCESS;
    }
}
