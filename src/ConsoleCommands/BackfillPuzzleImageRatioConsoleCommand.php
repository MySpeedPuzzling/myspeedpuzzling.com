<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Imagick;
use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'myspeedpuzzling:puzzle:backfill-image-ratio',
    description: 'Backfill image_ratio for existing puzzles by reading dimensions from S3 images',
)]
final class BackfillPuzzleImageRatioConsoleCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Number of puzzles to process per batch', '50');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done without updating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSizeOption = $input->getOption('batch-size');
        assert(is_string($batchSizeOption));
        $batchSize = (int) $batchSizeOption;
        $dryRun = $input->getOption('dry-run');

        /** @var array<array{id: string, image: string}> $puzzles */
        $puzzles = $this->connection->fetchAllAssociative(
            'SELECT id, image FROM puzzle WHERE image IS NOT NULL AND (image_ratio IS NULL OR image_ratio = 0)',
        );

        $total = count($puzzles);
        $io->info(sprintf('Found %d puzzles to backfill', $total));

        if ($total === 0) {
            $io->success('Nothing to backfill');
            return Command::SUCCESS;
        }

        $progressBar = $io->createProgressBar($total);
        $progressBar->start();

        $successCount = 0;
        $errorCount = 0;
        $batched = array_chunk($puzzles, max(1, $batchSize));

        foreach ($batched as $batch) {
            foreach ($batch as $puzzle) {
                try {
                    $ratio = $this->calculateRatio($puzzle['image']);

                    if ($ratio !== null && !$dryRun) {
                        $this->connection->update('puzzle', ['image_ratio' => $ratio], ['id' => $puzzle['id']]);
                    }

                    $successCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->logger->warning('Failed to backfill image ratio for puzzle {id}: {error}', [
                        'id' => $puzzle['id'],
                        'image' => $puzzle['image'],
                        'error' => $e->getMessage(),
                    ]);
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $prefix = $dryRun ? '[DRY RUN] Would have updated' : 'Updated';
        $io->success(sprintf('%s %d puzzles, %d errors', $prefix, $successCount, $errorCount));

        return Command::SUCCESS;
    }

    private function calculateRatio(string $imagePath): null|float
    {
        $content = $this->filesystem->read($imagePath);

        $imagick = new Imagick();

        try {
            $imagick->readImageBlob($content);

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            if ($height === 0 || $width === 0) {
                return null;
            }

            return $width / $height;
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }
}
