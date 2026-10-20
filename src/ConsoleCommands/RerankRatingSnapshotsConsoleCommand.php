<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RerankRatingSnapshots;
use SpeedPuzzling\Web\Query\GetPlayerRatingSnapshots;
use SpeedPuzzling\Web\Results\RatingSnapshotRerankResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    'myspeedpuzzling:rerank-rating-snapshots',
    'Ranks the stored MSP Rating places again by the ladder\'s rule, day by day (every stored day, or from a day on). Safe to run again.',
)]
final class RerankRatingSnapshotsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPlayerRatingSnapshots $getPlayerRatingSnapshots,
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Only the days from this one on (Y-m-d)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var null|string $from */
        $from = $input->getOption('from');
        $fromDay = null;

        if ($from !== null) {
            $fromDay = DateTimeImmutable::createFromFormat('!Y-m-d', $from);

            if ($fromDay === false || $fromDay->format('Y-m-d') !== $from) {
                $io->error('--from must be a day like 2026-10-01');

                return self::INVALID;
            }
        }

        $day = $fromDay ?? $this->getPlayerRatingSnapshots->firstDay();

        if ($day === null) {
            $io->note('No stored day');

            return self::SUCCESS;
        }

        // Snapshot days are UTC days (PuzzleIntelligenceRecalculator::recordRatingSnapshots())
        $today = new DateTimeImmutable($this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'));
        $startedAt = microtime(true);
        $days = 0;
        $ranked = 0;
        $unranked = 0;

        // One message per day: each day is its own transaction
        for (; $day <= $today; $day = $day->modify('+1 day')) {
            $envelope = $this->messageBus->dispatch(new RerankRatingSnapshots($day));
            $result = $envelope->last(HandledStamp::class)?->getResult();
            assert($result instanceof RatingSnapshotRerankResult);

            $days++;
            $ranked += $result->ranked;
            $unranked += $result->unranked;
        }

        $io->table(
            ['Days', 'Places written', 'Places cleared', 'Seconds'],
            [[$days, $ranked, $unranked, round(microtime(true) - $startedAt, 1)]],
        );

        return self::SUCCESS;
    }
}
