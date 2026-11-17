<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PublicStandingHead;

/**
 * A standing with its event's slugs in one statement - the head of a guest snapshot (competition toolkit v2 S1,
 * PublicStandingSnapshots): the slugs build the public page's URL. Whether the event is public is the caller's
 * (public_standing_state checks IsCompetitionPubliclyVisible on every request, before anything cached is read).
 */
readonly final class GetPublicStandingHead
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byId(string $standingId): null|PublicStandingHead
    {
        if (Uuid::isValid($standingId) === false) {
            return null;
        }

        $row = $this->database->fetchAssociative(<<<SQL
SELECT s.id, s.competition_id, s.name, s.slug, s.category, s.mode, s.round_ids, s.unfinished_penalty_seconds_per_piece, s.order_rule,
    s.missing_rule, s.penalise_finished_missing, s.position, s.published_at, c.slug AS event_slug, cs.slug AS series_slug
FROM competition_standing s
INNER JOIN competition c ON c.id = s.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE s.id = :id
SQL, ['id' => $standingId]);

        if ($row === false) {
            return null;
        }

        /** @var array{event_slug: null|string, series_slug: null|string} $row */
        return new PublicStandingHead(
            standing: GetCompetitionStandings::hydrate($row),
            eventSlug: $row['event_slug'],
            seriesSlug: $row['series_slug'],
        );
    }

    /**
     * The standing's event - never changes (a standing belongs to its event for life).
     */
    public function competitionOf(string $standingId): null|string
    {
        if (Uuid::isValid($standingId) === false) {
            return null;
        }

        $competitionId = $this->database->fetchOne('SELECT competition_id FROM competition_standing WHERE id = :id', ['id' => $standingId]);

        return is_string($competitionId) ? strtolower($competitionId) : null;
    }
}
