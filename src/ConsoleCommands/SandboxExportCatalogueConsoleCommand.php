<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Services\Sandbox\SandboxCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the public catalogue (SandboxCatalogue) for the sandbox, one row per line - READ-ONLY, meant to run on
 * production and be piped into a file on the box (docs/features/sandbox/README.md "Data"):
 *
 *   docker compose exec -T web php bin/console myspeedpuzzling:sandbox:export-catalogue | gzip > catalogue.ndjson.gz
 *
 * Writes nothing anywhere; the counts go to stderr so stdout stays the file.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sandbox:export-catalogue',
    description: 'Print the public catalogue (brands, puzzles, tags, public events) for the sandbox - read-only.',
)]
final class SandboxExportCatalogueConsoleCommand extends Command
{
    public function __construct(
        readonly private SandboxCatalogue $catalogue,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $counts = [];

        foreach ($this->catalogue->export() as $line) {
            $table = strstr($line, "\t", true);
            $counts[$table] = ($counts[$table] ?? 0) + 1;
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($counts as $table => $count) {
            $errorOutput->writeln(sprintf('%s: %d', $table, $count));
        }

        return self::SUCCESS;
    }
}
