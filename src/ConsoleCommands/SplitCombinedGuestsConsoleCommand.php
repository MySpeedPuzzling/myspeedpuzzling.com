<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SplitCombinedGuests;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand('myspeedpuzzling:split-combined-guests', 'Splits guests typed as several people ("Anna, Ben, Clara") into those people. Dry run unless --write.')]
final class SplitCombinedGuestsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Change the results (default: only list what would change)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = $input->getOption('write') === true;

        $envelope = $this->messageBus->dispatch(new SplitCombinedGuests(dryRun: $write === false));

        /** @var list<array{timeId: string, before: list<string>, after: list<string>}> $changes */
        $changes = $envelope->last(HandledStamp::class)?->getResult() ?? [];

        $io->table(
            ['Result', 'Before', 'After'],
            array_map(static fn(array $change): array => [
                $change['timeId'],
                implode(' | ', $change['before']),
                implode(' | ', $change['after']),
            ], $changes),
        );

        $io->success(sprintf('%d results %s.', count($changes), $write ? 'changed' : 'would change (dry run, add --write)'));

        return self::SUCCESS;
    }
}
