<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * Which events have a partner finder (docs/features/partner-finder.md D1): the marketplace events (in person, publicly
 * visible - drafts never -, dated, not over: GetMarketplaceEvents::SQL_QUALIFIES) that offer a pair or a team - a duo or
 * team round, or no rounds at all. An event whose rounds are all solo has no finder, nor an event without a slug - the
 * finder pages live under it (PartnerFinderUrls).
 */
readonly final class PartnerFinderEligibility
{
    /**
     * "Offers a pair or a team" alone. Requires alias `c` = competition.
     */
    public const string SQL_OFFERS_PAIR_OR_TEAM = "(EXISTS (SELECT 1 FROM competition_round pf_round WHERE pf_round.competition_id = c.id AND pf_round.category IN ('duo', 'team'))"
        . ' OR NOT EXISTS (SELECT 1 FROM competition_round pf_any_round WHERE pf_any_round.competition_id = c.id))';

    /**
     * The finder page lives under the event's slug - an old event without one has no finder.
     */
    public const string SQL_HAS_SLUG = 'c.slug IS NOT NULL';

    /**
     * The whole rule as a WHERE fragment. Requires aliases `c` = competition and `cs` = competition_series LEFT JOINed
     * on `cs.id = c.series_id`, and binds `:marketplace_today` - pass todayParameter() along.
     */
    public const string SQL_QUALIFIES = '(' . GetMarketplaceEvents::SQL_QUALIFIES . ' AND ' . self::SQL_HAS_SLUG . ' AND ' . self::SQL_OFFERS_PAIR_OR_TEAM . ')';

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private GetMarketplaceEvents $getMarketplaceEvents,
    ) {
    }

    /**
     * @return array{marketplace_today: string}
     */
    public function todayParameter(): array
    {
        return $this->getMarketplaceEvents->todayParameter();
    }

    /**
     * "This player is in a pair/team entry of that event" as an `EXISTS (…)` fragment: a not deleted participant row of
     * theirs with a round entry in a pair/team. Both arguments are SQL expressions (`c.id`, `:playerId`), never values.
     */
    public static function sqlPlayerEntered(string $competitionIdSql, string $playerIdSql): string
    {
        return <<<SQL
EXISTS (
    SELECT 1
    FROM competition_participant entered_participant
    INNER JOIN competition_participant_round entered_entry ON entered_entry.participant_id = entered_participant.id
    WHERE entered_participant.competition_id = {$competitionIdSql}
        AND entered_participant.player_id = {$playerIdSql}
        AND entered_participant.deleted_at IS NULL
        AND entered_entry.team_id IS NOT NULL
)
SQL;
    }

    /**
     * What the event row and its rounds already tell without a query: an online, undated, slugless, past, rejected or
     * draft event never qualifies, nor one whose rounds are all solo; a one-time event needs its approval. Only a cheap pre-check -
     * SQL_QUALIFIES stays the rule (an edition's series approval included): true never promises a finder, false always
     * means none. PartnerFinderEligibilityTest pins it against the SQL.
     *
     * @param list<RoundCategory> $roundCategories every round of the event, [] = no rounds
     */
    public function mightQualify(CompetitionEvent $competition, array $roundCategories): bool
    {
        if ($competition->isOnline || $competition->dateFrom === null || $competition->slug === null) {
            return false;
        }

        if ($competition->isDraft || $competition->rejectedAt !== null) {
            return false;
        }

        if ($competition->seriesId === null && $competition->approvedAt === null) {
            return false;
        }

        $lastDay = $competition->dateTo ?? $competition->dateFrom;

        if ($lastDay->format('Y-m-d') < $this->clock->now()->format('Y-m-d')) {
            return false;
        }

        return PartnerFinderOffer::fromRoundCategories($roundCategories)->offersAny();
    }

    /**
     * What the event offers (by its rounds) when it has a partner finder - null when it has none.
     */
    public function qualifyingOffer(string $competitionId): null|PartnerFinderOffer
    {
        if (Uuid::isValid($competitionId) === false) {
            return null;
        }

        $qualifies = self::SQL_QUALIFIES;

        $query = <<<SQL
SELECT
    (
        SELECT COALESCE(array_to_json(array_agg(DISTINCT cr.category)), '[]')
        FROM competition_round cr
        WHERE cr.competition_id = c.id
    ) AS categories,
    (
        SELECT MAX(cr.team_size)
        FROM competition_round cr
        WHERE cr.competition_id = c.id
            AND cr.category = 'team'
    ) AS team_size
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
    AND {$qualifies}
SQL;

        /** @var false|array{categories: string, team_size: null|int|string} $row */
        $row = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                ...$this->todayParameter(),
            ])
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return self::offerFromRow($row['categories'], $row['team_size']);
    }

    private static function offerFromRow(string $categoriesJson, null|int|string $teamSize): PartnerFinderOffer
    {
        /** @var list<string> $values */
        $values = json_decode($categoriesJson, true, flags: JSON_THROW_ON_ERROR);
        $categories = array_values(array_filter(array_map(RoundCategory::tryFrom(...), $values)));

        return PartnerFinderOffer::fromRoundCategories($categories, $teamSize !== null ? (int) $teamSize : null);
    }
}
