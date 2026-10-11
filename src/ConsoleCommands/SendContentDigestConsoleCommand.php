<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PlanContentDigestRuns;
use SpeedPuzzling\Web\Results\ContentDigestRunPlan;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
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
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED, '"activity" (Mondays) or "miss-you" (Tuesdays)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the recipients');
        $this->addOption('max-recipients', null, InputOption::VALUE_REQUIRED, 'Queue at most this many recipients (all frequencies together, most engaged first, the holdout included) - e.g. for the first wave');
        $this->addOption('last-activity-after', null, InputOption::VALUE_REQUIRED, '"We miss you" only: just players whose last result is after this - a date (2026-04-27) or an age ("6 months", "1 year")');
        $this->addOption('last-activity-before', null, InputOption::VALUE_REQUIRED, '"We miss you" only: just players whose last result is before this - a date or an age ("6 months")');
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

        try {
            $lastActivityAfter = $this->moment($input->getOption('last-activity-after'));
            $lastActivityBefore = $this->moment($input->getOption('last-activity-before'));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return self::INVALID;
        }

        $envelope = $this->messageBus->dispatch(new PlanContentDigestRuns(
            kind: $kind,
            dryRun: (bool) $input->getOption('dry-run'),
            maxRecipients: $maxRecipients === null ? null : (int) $maxRecipients,
            lastActivityAfter: $lastActivityAfter,
            lastActivityBefore: $lastActivityBefore,
        ));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var list<ContentDigestRunPlan> $plans */
        $plans = $handledStamp->getResult();

        $io->table(
            ['Frequency', 'Period', 'Outcome', 'Recipients', 'Holdout', 'Spacing', '28d-3m', '3-9m', 'Older, visited'],
            array_map(static fn (ContentDigestRunPlan $plan): array => [
                $plan->frequency->value,
                $plan->periodKey,
                $plan->outcome,
                $plan->recipients,
                $plan->heldOut,
                $plan->spacingMs > 0 ? sprintf('%.1f s', $plan->spacingMs / 1000) : '',
                $plan->buckets['28d-3m'] ?? '',
                $plan->buckets['3-9m'] ?? '',
                $plan->buckets['older-visited'] ?? '',
            ], $plans),
        );

        return self::SUCCESS;
    }

    /**
     * A date (Europe/Prague midnight) or an age counted back from now ("28 days", "6 months", "1 year").
     */
    private function moment(mixed $value): null|DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, DigestPeriod::zone());

            if ($date !== false) {
                return $date;
            }
        }

        if (is_string($value) && preg_match('/^(\d+)\s*(day|days|week|weeks|month|months|year|years)$/', trim($value), $matches) === 1) {
            return $this->clock->now()->modify(sprintf('-%d %s', (int) $matches[1], $matches[2]));
        }

        throw new \InvalidArgumentException(sprintf('"%s" is neither a date (2026-04-27) nor an age ("6 months").', is_scalar($value) ? (string) $value : ''));
    }
}
