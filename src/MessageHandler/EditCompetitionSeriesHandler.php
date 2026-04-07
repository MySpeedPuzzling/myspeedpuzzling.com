<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
readonly final class EditCompetitionSeriesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionSeriesRepository $seriesRepository,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private SluggerInterface $slugger,
    ) {
    }

    public function __invoke(EditCompetitionSeries $message): void
    {
        $series = $this->seriesRepository->get($message->seriesId);

        $logoPath = $series->logo;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $logoPath = "competitions/{$message->seriesId}-{$timestamp}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $slug = $series->slug;
        if ($series->name !== $message->name) {
            $slug = $this->generateUniqueSlug($message->name, $message->seriesId);
        }

        $series->edit(
            name: $message->name,
            slug: $slug,
            logo: $logoPath,
            description: $message->description,
            link: $message->link,
            isOnline: $message->isOnline,
            location: $message->location,
            locationCountryCode: $message->locationCountryCode,
            shortcut: $message->shortcut,
        );

        $series->maintainers->clear();
        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $series->maintainers->add($maintainer);
        }
    }

    private function generateUniqueSlug(string $name, string $seriesId): string
    {
        $slug = (string) $this->slugger->slug(strtolower($name));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition_series WHERE slug = :slug AND id != :id',
                ['slug' => $slug, 'id' => $seriesId],
            )
            ->fetchOne();
        $existingCount = (int) $existingCount;

        if ($existingCount > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }
}
