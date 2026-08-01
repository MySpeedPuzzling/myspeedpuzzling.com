<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Services\Storage\UploadSpoolProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('myspeedpuzzling:upload-spooled-files')]
final class UploadSpooledFilesConsoleCommand extends Command
{
    public function __construct(
        readonly private UploadSpoolProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->processor->process();

        if ($result['skipped']) {
            $io->note('Another upload-spool run holds the lock, skipping.');

            return self::SUCCESS;
        }

        $io->success(sprintf(
            'Upload spool processed: %d uploaded, %d deleted, %d failed, %d remaining.',
            $result['uploaded'],
            $result['deleted'],
            $result['failed'],
            $result['remaining'],
        ));

        return self::SUCCESS;
    }
}
