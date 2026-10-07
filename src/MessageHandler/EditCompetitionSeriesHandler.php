<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCompetitionSeriesHandler
{
    public function __construct(
        private CompetitionSeriesRepository $seriesRepository,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private CompetitionSlugGenerator $slugGenerator,
    ) {
    }

    /**
     * @throws CompetitionSlugTaken
     * @throws InvalidCompetitionSlug
     */
    public function __invoke(EditCompetitionSeries $message): void
    {
        $series = $this->seriesRepository->get($message->seriesId);

        // A rename keeps the slug (published links know the series by it) - only an explicitly chosen one changes it
        $slug = $series->slug;

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isSeriesSlugTaken($message->slug, $message->seriesId)) {
                throw new CompetitionSlugTaken($message->slug);
            }

            $slug = $message->slug;
        }

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
}
