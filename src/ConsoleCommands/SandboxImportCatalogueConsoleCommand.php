<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\ImportSandboxCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Loads a file of myspeedpuzzling:sandbox:export-catalogue into the sandbox (docs/features/sandbox/README.md "Data").
 * Refuses anywhere but the sandbox (SANDBOX=1). Safe to run again: rows already there are skipped.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sandbox:import-catalogue',
    description: 'Load an exported public catalogue into the sandbox (SANDBOX=1 only).',
)]
final class SandboxImportCatalogueConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The export (.ndjson or .ndjson.gz)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('file');
        assert(is_string($path));

        $envelope = $this->messageBus->dispatch(new ImportSandboxCatalogue($path));
        /** @var array<string, int> $counts */
        $counts = $envelope->last(HandledStamp::class)?->getResult() ?? [];

        $io->table(['Table', 'Rows in the file'], array_map(
            static fn (string $table, int $count): array => [$table, $count],
            array_keys($counts),
            array_values($counts),
        ));
        $io->success('Imported - rows that were there already were skipped.');

        return self::SUCCESS;
    }
}
