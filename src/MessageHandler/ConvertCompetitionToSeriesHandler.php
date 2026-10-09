<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Exceptions\CompetitionAlreadyInSeries;
use SpeedPuzzling\Web\Exceptions\CompetitionNotConvertible;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\EventUrlRedirectRepository;
use SpeedPuzzling\Web\Repository\FollowedCompetitionRepository;
use SpeedPuzzling\Web\Services\Restructuring\EventUrlRedirects;
use SpeedPuzzling\Web\Value\EventUrlPath;
use SpeedPuzzling\Web\Value\SeriesConversionBlocker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * docs/features/events-page/high-frequency-series.md "The conversion tool". The series is created from the event the
 * same way either way (its organization, tag, maintainers, approval, draft state and "Who can enter"; its followers
 * follow the series). Then:
 *
 * - keepAsEdition (the web button): the event becomes the series' first edition - its times stay explicit on it;
 * - otherwise the event becomes the series: every time of it becomes a series pick of the new series (series-level -
 *   a new series has no editions, its first edition re-matches them), its old address leads to the series and the
 *   competition row goes. Refused while anything of it would be lost (CompetitionNotConvertible) - checked before
 *   anything changes. No reconcile event: there is nothing to match yet (P12).
 */
#[AsMessageHandler]
readonly final class ConvertCompetitionToSeriesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRepository $competitionRepository,
        private FollowedCompetitionRepository $followedCompetitionRepository,
        private ClockInterface $clock,
        private SluggerInterface $slugger,
        private EventUrlRedirectRepository $eventUrlRedirectRepository,
        private EventUrlRedirects $eventUrlRedirects,
    ) {
    }

    /**
     * @throws CompetitionAlreadyInSeries
     * @throws CompetitionNotConvertible
     */
    public function __invoke(ConvertCompetitionToSeries $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        if ($competition->series !== null) {
            throw new CompetitionAlreadyInSeries();
        }

        if ($message->keepAsEdition === false) {
            $blockers = $this->blockers($competition, $message->dropParticipants);

            if ($blockers !== []) {
                throw new CompetitionNotConvertible($blockers);
            }
        }

        $now = $this->clock->now();
        $seriesSlug = $this->generateUniqueSeriesSlug($competition->slug ?? $competition->name);

        $series = new CompetitionSeries(
            id: $message->seriesId,
            name: $competition->name,
            slug: $seriesSlug,
            logo: $competition->logo,
            description: $competition->description,
            link: $competition->link,
            isOnline: $competition->isOnline,
            location: $competition->location,
            locationCountryCode: $competition->locationCountryCode,
            shortcut: $competition->shortcut,
            tag: $competition->tag,
            addedByPlayer: $competition->addedByPlayer,
            approvedAt: $competition->approvedAt,
            approvedByPlayer: $competition->approvedByPlayer,
            // A rejection vetoes a stale approval (IsSeriesPubliclyVisible) - converting must not make a rejected event public
            rejectedAt: $competition->rejectedAt,
            rejectedByPlayer: $competition->rejectedByPlayer,
            rejectionReason: $competition->rejectionReason,
            createdAt: $now,
            // The series takes over what belongs to the whole: its organization, its draft state, "Who can enter"
            organization: $competition->organization,
            isDraft: $competition->isDraft,
            eligibility: $competition->eligibility,
        );

        foreach ($competition->maintainers as $maintainer) {
            $series->maintainers->add($maintainer);
        }

        $this->entityManager->persist($series);

        if ($message->keepAsEdition) {
            $this->keepAsFirstEdition($competition, $series);
        }

        // An edition is followed through its series - the event's followers follow the new series
        foreach ($this->followedCompetitionRepository->listForCompetition($competition) as $followed) {
            $followed->moveToSeries($series);
        }

        $this->entityManager->flush();

        if ($message->keepAsEdition === false) {
            $this->becomeTheSeries($competition, $series);
        }
    }

    private function keepAsFirstEdition(Competition $competition, CompetitionSeries $series): void
    {
        $competition->maintainers->clear();
        // An edition never has its own organization - it is the series' from now on (before it gets the series)
        $competition->assignOrganization(null);
        $competition->series = $series;
        // Its draft state and "Who can enter" are the series' now
        $competition->publish();
        $competition->changeEligibility(null);
        $competition->shortcut = null;
        $competition->logo = null;
        $competition->description = null;
        $competition->link = null;
        $competition->tag = null;
        $competition->approvedAt = null;
        $competition->approvedByPlayer = null;
        $competition->rejectedAt = null;
        $competition->rejectedByPlayer = null;
        $competition->rejectionReason = null;
    }

    /**
     * After the series is flushed: the event's times, participants and old addresses go to the series, then the event
     * row goes (its maintainer links with it; ConvertCompetitionForeignKeyCoverageTest lists every reference to it).
     */
    private function becomeTheSeries(Competition $competition, CompetitionSeries $series): void
    {
        $database = $this->entityManager->getConnection();
        $params = ['competitionId' => $competition->id->toString()];

        // A genuine bulk operation - the umbrella event of a weekly series holds thousands of times: each becomes a
        // series pick of the new series, series-level (a new series has no edition to match), with nothing changed
        // about the time itself. Its first edition re-matches them (SeriesEditionsChanged)
        $database->executeStatement(
            'UPDATE puzzle_solving_time
             SET competition_series_id = :seriesId, competition_id = NULL, competition_round_id = NULL, series_edition_match = NULL
             WHERE competition_id = :competitionId',
            [...$params, 'seriesId' => $series->id->toString()],
        );

        // The participants - the blockers allowed them only with dropParticipants; removed ones (soft-deleted) never
        // block, their rows go too. The participant sheet's change trail goes with them
        $database->executeStatement(
            'DELETE FROM participant_sheet_change_receipt WHERE competition_id = :competitionId',
            $params,
        );
        $database->executeStatement(
            'DELETE FROM competition_participant_round
             WHERE participant_id IN (SELECT id FROM competition_participant WHERE competition_id = :competitionId)',
            $params,
        );
        $database->executeStatement(
            'DELETE FROM competition_participant WHERE competition_id = :competitionId',
            $params,
        );

        // Old addresses that led to the event lead to the series now - they would cascade with the event otherwise
        foreach ($this->eventUrlRedirectRepository->findPointingAtCompetition($competition) as $redirect) {
            $redirect->pointTo($series);
        }

        if ($competition->slug !== null) {
            $this->eventUrlRedirects->remember(EventUrlPath::event($competition->slug), $series);
        }

        $this->competitionRepository->delete($competition);
    }

    /**
     * What `keepAsEdition: false` would lose - in one statement. Participants block only without dropParticipants;
     * removed (soft-deleted) participants never.
     *
     * @return list<SeriesConversionBlocker>
     */
    private function blockers(Competition $competition, bool $dropParticipants): array
    {
        /** @var false|array<string, bool> $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            <<<SQL
SELECT
    EXISTS (SELECT 1 FROM competition_round WHERE competition_id = :competitionId) AS rounds,
    (
        EXISTS (
            SELECT 1 FROM competition_participant_round cpr
            INNER JOIN competition_round cr ON cr.id = cpr.round_id
            WHERE cr.competition_id = :competitionId
                AND (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL OR cpr.result_did_not_start OR cpr.qualified_at IS NOT NULL)
        )
        OR EXISTS (
            SELECT 1 FROM competition_team ct
            INNER JOIN competition_round cr ON cr.id = ct.round_id
            WHERE cr.competition_id = :competitionId
                AND (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL OR ct.result_did_not_start OR ct.qualified_at IS NOT NULL)
        )
    ) AS official_results,
    EXISTS (SELECT 1 FROM competition_referee WHERE competition_id = :competitionId) AS referees,
    EXISTS (SELECT 1 FROM competition_page_section WHERE competition_id = :competitionId) AS page_sections,
    EXISTS (SELECT 1 FROM sell_swap_list_item_event WHERE competition_id = :competitionId) AS marketplace_marks,
    EXISTS (SELECT 1 FROM competition_participant WHERE competition_id = :competitionId AND deleted_at IS NULL) AS participants
SQL,
            ['competitionId' => $competition->id->toString()],
        );

        $blockers = [];

        foreach (SeriesConversionBlocker::cases() as $blocker) {
            if ($row === false || ($row[$blocker->value] ?? false) !== true) {
                continue;
            }

            if ($blocker === SeriesConversionBlocker::Participants && $dropParticipants) {
                continue;
            }

            $blockers[] = $blocker;
        }

        return $blockers;
    }

    private function generateUniqueSeriesSlug(string $source): string
    {
        $slug = (string) $this->slugger->slug(strtolower($source));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition_series WHERE slug = :slug',
                ['slug' => $slug],
            )
            ->fetchOne();
        $existingCount = (int) $existingCount;

        if ($existingCount > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }
}
