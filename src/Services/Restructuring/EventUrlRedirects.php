<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Restructuring;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\EventUrlRedirect;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\EventUrlRedirectRepository;
use SpeedPuzzling\Web\Value\EventUrlPath;

/**
 * Writes the rows of event_url_redirect (docs/features/organizations/README.md, D6) - only the restructuring handlers
 * call it (MoveEditionToSeries, MoveRoundToCompetition, CreateOrganizationFromSeries). A path that has a row already
 * points to the new target: the key is the path, so the last move of a path wins. The rows are read by
 * GetEventUrlRedirect when a page answers 404 (EventUrlRedirectSubscriber).
 */
readonly final class EventUrlRedirects
{
    public function __construct(
        private EventUrlRedirectRepository $eventUrlRedirectRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function remember(EventUrlPath $path, Organization|CompetitionSeries|Competition|CompetitionRound $target): void
    {
        $redirect = $this->eventUrlRedirectRepository->findByPath($path);

        if ($redirect !== null) {
            $redirect->pointTo($target);

            return;
        }

        $this->eventUrlRedirectRepository->save(EventUrlRedirect::to(Uuid::uuid7(), $path, $target, $this->clock->now()));
    }

    /**
     * The old address of an edition (`/series/{seriesSlug}/{editionSlug}`) and of each of its rounds' results pages,
     * all pointing to where they are now. Call it with the slugs the edition had before it changed.
     */
    public function rememberEdition(Competition $edition, string $seriesSlug, string $editionSlug): void
    {
        $this->remember(EventUrlPath::edition($seriesSlug, $editionSlug), $edition);

        foreach ($this->roundSlugs($edition) as $roundId => $roundSlug) {
            $this->remember(
                EventUrlPath::editionRound($seriesSlug, $editionSlug, $roundSlug),
                $this->competitionRoundRepository->get($roundId),
            );
        }
    }

    /**
     * A series that changed its slug when it became an organization's (CreateOrganizationFromSeries): its old address
     * leads to the organization, every old edition and round results address to where that page is now.
     */
    public function rememberSeriesOfOrganization(CompetitionSeries $series, string $oldSeriesSlug, Organization $organization): void
    {
        $this->remember(EventUrlPath::series($oldSeriesSlug), $organization);

        /** @var list<array{id: string, slug: string}> $editions */
        $editions = $this->database->fetchAllAssociative(
            'SELECT id, slug FROM competition WHERE series_id = :seriesId AND slug IS NOT NULL ORDER BY date_from NULLS LAST, id',
            ['seriesId' => $series->id->toString()],
        );

        foreach ($editions as $edition) {
            $this->rememberEdition($this->competitionRepository->get($edition['id']), $oldSeriesSlug, $edition['slug']);
        }
    }

    /**
     * The slugs of the competition's rounds as stored now, by round id - a round without a slug has no results address.
     *
     * @return array<string, string>
     */
    public function roundSlugs(Competition $competition): array
    {
        /** @var list<array{id: string, slug: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, slug FROM competition_round WHERE competition_id = :competitionId AND slug IS NOT NULL ORDER BY starts_at, id',
            ['competitionId' => $competition->id->toString()],
        );

        $slugs = [];

        foreach ($rows as $row) {
            $slugs[$row['id']] = $row['slug'];
        }

        return $slugs;
    }
}
