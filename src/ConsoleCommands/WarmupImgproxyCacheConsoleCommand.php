<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'myspeedpuzzling:imgproxy:warmup',
    description: 'Warmup imgproxy cache by requesting all image thumbnails',
)]
final class WarmupImgproxyCacheConsoleCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly string $nginxProxyBaseUrl,
        private readonly string $imgproxyBucket,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Concurrent requests', '20');
        $this->addOption('preset', 'p', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Presets to warm');
        $this->addOption('jpeg', null, InputOption::VALUE_NONE, 'Also warm JPEG variant (for Safari/OG crawlers)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $concurrency = (int) $input->getOption('concurrency');

        /** @var array<string> $presets */
        $presets = $input->getOption('preset');
        $warmJpeg = $input->getOption('jpeg');

        $imageGroups = $this->loadImagePaths($presets);

        $totalImages = array_sum(array_map('count', $imageGroups));
        $totalRequests = $totalImages * ($warmJpeg ? 2 : 1);

        $io->info(sprintf('Warming %d images (%d requests) with concurrency %d', $totalImages, $totalRequests, $concurrency));

        $successCount = 0;
        $errorCount = 0;
        $progressBar = $io->createProgressBar($totalRequests);
        $progressBar->start();

        $acceptHeaders = ['image/avif,image/webp,image/jpeg'];
        if ($warmJpeg) {
            $acceptHeaders[] = 'image/jpeg';
        }

        $responses = [];

        foreach ($imageGroups as $preset => $paths) {
            foreach ($paths as $path) {
                foreach ($acceptHeaders as $accept) {
                    $url = sprintf(
                        '%s/preset:%s/plain/s3://%s/%s',
                        $this->nginxProxyBaseUrl,
                        $preset,
                        $this->imgproxyBucket,
                        ltrim($path, '/'),
                    );

                    $responses[] = $this->httpClient->request('GET', $url, [
                        'headers' => ['Accept' => $accept],
                    ]);

                    // Process completed responses when we hit concurrency limit
                    if (count($responses) >= $concurrency) {
                        foreach ($responses as $response) {
                            try {
                                $response->getStatusCode();
                                $successCount++;
                            } catch (\Throwable) {
                                $errorCount++;
                            }
                            $progressBar->advance();
                        }
                        $responses = [];
                    }
                }
            }
        }

        // Process remaining responses
        foreach ($responses as $response) {
            try {
                $response->getStatusCode();
                $successCount++;
            } catch (\Throwable) {
                $errorCount++;
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('Warmed %d images successfully, %d errors', $successCount, $errorCount));

        return Command::SUCCESS;
    }

    /**
     * @param array<string> $presets
     * @return array<string, array<string>>
     */
    private function loadImagePaths(array $presets): array
    {
        $result = [];

        $shouldWarm = fn(string $preset): bool => $presets === [] || in_array($preset, $presets, true);

        if ($shouldWarm('puzzle_small') || $shouldWarm('puzzle_medium')) {
            $puzzleImages = $this->connection->fetchFirstColumn(
                'SELECT image FROM puzzle WHERE image IS NOT NULL',
            );

            if ($shouldWarm('puzzle_small')) {
                $result['puzzle_small'] = array_merge($result['puzzle_small'] ?? [], $puzzleImages);
            }
            if ($shouldWarm('puzzle_medium')) {
                $result['puzzle_medium'] = array_merge($result['puzzle_medium'] ?? [], $puzzleImages);
            }
        }

        if ($shouldWarm('puzzle_small') || $shouldWarm('puzzle_medium')) {
            $solvingPhotos = $this->connection->fetchFirstColumn(
                'SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE finished_puzzle_photo IS NOT NULL',
            );

            if ($shouldWarm('puzzle_small')) {
                $result['puzzle_small'] = array_merge($result['puzzle_small'] ?? [], $solvingPhotos);
            }
            if ($shouldWarm('puzzle_medium')) {
                $result['puzzle_medium'] = array_merge($result['puzzle_medium'] ?? [], $solvingPhotos);
            }
        }

        if ($shouldWarm('avatar') || $shouldWarm('puzzle_small')) {
            $avatars = $this->connection->fetchFirstColumn(
                'SELECT avatar FROM player WHERE avatar IS NOT NULL',
            );

            if ($shouldWarm('avatar')) {
                $result['avatar'] = array_merge($result['avatar'] ?? [], $avatars);
            }
            if ($shouldWarm('puzzle_small')) {
                $result['puzzle_small'] = array_merge($result['puzzle_small'] ?? [], $avatars);
            }
        }

        if ($shouldWarm('puzzle_small')) {
            $competitionLogos = $this->connection->fetchFirstColumn(
                'SELECT logo FROM competition WHERE logo IS NOT NULL',
            );
            $result['puzzle_small'] = array_merge($result['puzzle_small'] ?? [], $competitionLogos);

            $manufacturerLogos = $this->connection->fetchFirstColumn(
                'SELECT logo FROM manufacturer WHERE logo IS NOT NULL',
            );
            $result['puzzle_small'] = array_merge($result['puzzle_small'] ?? [], $manufacturerLogos);
        }

        return $result;
    }
}
