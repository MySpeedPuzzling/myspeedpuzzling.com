<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\FitGroupPredictionModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    'myspeedpuzzling:fit-group-prediction-model',
    'Fits the pair/team prediction model (never shown) and, while PREDICTION_GROUP_ROWS=1 (or with --rows), writes its side-by-side rows. Daily, after myspeedpuzzling:fit-prediction-model.',
)]
final class FitGroupPredictionModelConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rows', null, InputOption::VALUE_NONE, 'Write the rows even while PREDICTION_GROUP_ROWS=0');
        $this->addOption('recompute', null, InputOption::VALUE_NONE, 'Make every reconstructed row again with the new fit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var array<string, int|float|string> $summary */
        $summary = $this->messageBus->dispatch(new FitGroupPredictionModel(
            writeRows: $input->getOption('rows') === true,
            recompute: $input->getOption('recompute') === true,
        ))->last(HandledStamp::class)?->getResult();

        $io->table(['Setting', 'Value'], array_map(static fn (string $key, int|float|string $value): array => [$key, $value], array_keys($summary), $summary));
        $io->success('Pair/team prediction model fitted.');

        return self::SUCCESS;
    }
}
