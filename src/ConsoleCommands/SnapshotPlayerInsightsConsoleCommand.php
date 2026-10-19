<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\InsightsSnapshotMonthNotAllowed;
use SpeedPuzzling\Web\Message\SnapshotPlayerInsights;
use SpeedPuzzling\Web\Services\Insights\InsightsSnapshotCriteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:snapshot-player-insights',
    description: 'Monthly: store every active player\'s Insights snapshot (strengths, weak spots, form, rating) for "what changed since last month"',
)]
final class SnapshotPlayerInsightsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'The month to store the snapshots as (Y-m): the current one (default), or the previous one in a month\'s first 3 days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $month = $input->getOption('month');

        if ($month !== null && (is_string($month) === false || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1)) {
            $io->error('--month must be Y-m, e.g. 2026-11.');

            return Command::INVALID;
        }

        try {
            InsightsSnapshotCriteria::month($month, $this->clock->now());
        } catch (InsightsSnapshotMonthNotAllowed $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $started = microtime(true);
        $envelope = $this->messageBus->dispatch(new SnapshotPlayerInsights($month));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{month: string, players: int, written: int} $result */
        $result = $handledStamp->getResult();

        $io->success(sprintf(
            'Insights snapshots of %s: %d players, %d rows written in %.1f s.',
            $result['month'],
            $result['players'],
            $result['written'],
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }
}
