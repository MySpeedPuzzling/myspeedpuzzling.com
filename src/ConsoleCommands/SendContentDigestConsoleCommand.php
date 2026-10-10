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
    description: 'Queue the activity digests (Monday, kind "activity") of every frequency whose period is due, or the "We miss you" e-mails (Tuesday, kind "miss-you"), spaced over the day',
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
        $this->addArgument('kind', InputArgument::REQUIRED, '"activity" (Mondays) or "miss-you" (Tuesdays)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the recipients');
        $this->addOption('max-recipients', null, InputOption::VALUE_REQUIRED, 'Queue at most this many e-mails (all frequencies together, most engaged first) - e.g. for the first wave');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('kind');
        $kind = is_string($argument) ? ContentDigestKind::tryFrom(str_replace('-', '_', $argument)) : null;

        if ($kind === null) {
            $io->error('The kind is "activity" or "miss-you".');

            return self::INVALID;
        }

        $maxRecipients = $input->getOption('max-recipients');

        if ($maxRecipients !== null && (is_string($maxRecipients) === false || ctype_digit($maxRecipients) === false)) {
            $io->error('--max-recipients is a whole number.');

            return self::INVALID;
        }

        $envelope = $this->messageBus->dispatch(new PlanContentDigestRuns(
            kind: $kind,
            dryRun: (bool) $input->getOption('dry-run'),
            maxRecipients: $maxRecipients === null ? null : (int) $maxRecipients,
        ));

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
