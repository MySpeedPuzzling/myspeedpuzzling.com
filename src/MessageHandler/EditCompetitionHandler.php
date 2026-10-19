<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OnlineTimingInUse;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Query\GetRoundsUsingOnlineTiming;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
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
     * @throws OnlineTimingInUse a one-time event with a round using online timing changed to in person
     */
    public function __invoke(EditCompetition $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        // Online timing works only at an online event (docs/features/online-competitions/): a one-time event with a
        // round using it stays online. An edition is online by its series (EventIsOnline) - its own flag changes nothing
        if ($competition->series === null && $competition->isOnline && $message->isOnline === false) {
            $roundsUsingOnlineTiming = $this->getRoundsUsingOnlineTiming->ofCompetition($competition->id->toString());

            if ($roundsUsingOnlineTiming !== []) {
                throw new OnlineTimingInUse(OnlineTimingInUse::EVENT_IN_PERSON, $roundsUsingOnlineTiming);
            }
        }

        // A rename keeps the slug - only an explicitly chosen one changes it. Checked before the logo is stored
        $slug = $competition->slug;

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isTaken($message->slug, $competition->series?->id->toString(), $message->competitionId)) {
                throw new CompetitionSlugTaken($message->slug);
            }

            $slug = $message->slug;
        }

        $logoPath = $competition->logo;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $logoPath = "competitions/{$message->competitionId}-{$timestamp}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $competition->edit(
            name: $message->name,
            slug: $slug,
            shortcut: $message->shortcut,
            logo: $logoPath,
            description: $message->description,
            link: $message->link,
            registrationLink: $message->registrationLink,
            resultsLink: $message->resultsLink,
            location: $message->location,
            locationCountryCode: $message->locationCountryCode,
            dateFrom: $message->dateFrom,
            dateTo: $message->dateTo,
            isOnline: $message->isOnline,
        );
        $competition->changeEligibility($message->eligibility);
        $competition->changeEventTypes($message->eventTypes);

        // Sync maintainers
        $competition->maintainers->clear();
        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $competition->maintainers->add($maintainer);
        }
    }
}
