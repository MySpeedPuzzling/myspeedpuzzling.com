<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OnlineTimingInUse;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Query\GetRoundsUsingOnlineTiming;
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
        private GetRoundsUsingOnlineTiming $getRoundsUsingOnlineTiming,
    ) {
    }

    /**
     * @throws CompetitionSlugTaken
     * @throws InvalidCompetitionSlug
     * @throws OnlineTimingInUse a series an edition of which has a round using online timing changed to in person
     */
    public function __invoke(EditCompetitionSeries $message): void
    {
        $series = $this->seriesRepository->get($message->seriesId);

        // Its editions are online by the series (EventIsOnline) and online timing works only at an online event
        // (docs/features/online-competitions/): a series with an edition's round using it stays online
        if ($series->isOnline && $message->isOnline === false) {
            $roundsUsingOnlineTiming = $this->getRoundsUsingOnlineTiming->ofSeries($series->id->toString());

            if ($roundsUsingOnlineTiming !== []) {
                throw new OnlineTimingInUse(OnlineTimingInUse::SERIES_IN_PERSON, $roundsUsingOnlineTiming);
            }
        }

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
        $series->changeEligibilityAndSchedule($message->eligibility, $message->schedule);
        $series->changeEventTypes($message->eventTypes);

        $series->maintainers->clear();
        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $series->maintainers->add($maintainer);
        }
    }
}
