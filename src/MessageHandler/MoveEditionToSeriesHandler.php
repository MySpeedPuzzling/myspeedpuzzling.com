<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\EditionAlreadyInSeries;
use SpeedPuzzling\Web\Exceptions\EditionNotMovableIntoDraft;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\NotAnEdition;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\Drafts\UnpublishBlockers;
use SpeedPuzzling\Web\Services\Restructuring\EventUrlRedirects;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * docs/features/organizations/README.md "Restructuring tools": the edition keeps its slug when it is free in the target
 * series (else the caller sends a new one - CompetitionSlugTaken otherwise); its place follows the target series where
 * it was its old series' (P21); its organization and visibility are the target series' from now on. Participants,
 * rounds, results and solving times stay with it. Its old address and its rounds' results addresses redirect. A draft
 * series takes only an edition without participants, official results and linked solving times (UnpublishBlockers -
 * a draft never holds those).
 */
#[AsMessageHandler]
readonly final class MoveEditionToSeriesHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private PlayerRepository $playerRepository,
        private CompetitionSlugGenerator $slugGenerator,
        private EventUrlRedirects $eventUrlRedirects,
        private UnpublishBlockers $unpublishBlockers,
    ) {
    }

    /**
     * @throws NotAnEdition
     * @throws EditionAlreadyInSeries
     * @throws InvalidCompetitionSlug
     * @throws CompetitionSlugTaken
     * @throws EditionNotMovableIntoDraft
     */
    public function __invoke(MoveEditionToSeries $message): void
    {
        $edition = $this->competitionRepository->get($message->competitionId);
        $oldSeries = $edition->series;

        if ($oldSeries === null) {
            throw new NotAnEdition();
        }

        $target = $this->competitionSeriesRepository->get($message->targetSeriesId);
        // Who moves it - the caller checked the rights, the player must exist
        $this->playerRepository->get($message->actingPlayerId);

        if ($target->id->equals($oldSeries->id)) {
            throw new EditionAlreadyInSeries();
        }

        if ($message->newSlug !== null && CompetitionSlugGenerator::isValid($message->newSlug) === false) {
            throw new InvalidCompetitionSlug($message->newSlug);
        }

        $oldSeriesSlug = $oldSeries->slug;
        $oldSlug = $edition->slug;
        $slug = $message->newSlug ?? $oldSlug ?? $this->slugGenerator->normalize($edition->name);

        if ($slug === '' || $this->slugGenerator->isTaken($slug, $target->id->toString(), $edition->id->toString())) {
            throw new CompetitionSlugTaken($slug);
        }

        // A draft series never holds participants, official results or linked times - their listings would show it
        if ($target->isDraft) {
            $check = $this->unpublishBlockers->forCompetition($edition->id->toString());

            if ($check->allowed() === false) {
                throw new EditionNotMovableIntoDraft($check->blockers());
            }
        }

        $edition->moveToSeries($target, $slug);

        if ($oldSeriesSlug !== null && $oldSlug !== null) {
            $this->eventUrlRedirects->rememberEdition($edition, $oldSeriesSlug, $oldSlug);
        }
    }
}
