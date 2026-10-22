<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\PartnerListing;

/**
 * Who the owner of a listing can name in "Found my partner" - "Who did you team up with?" (docs/features/partner-finder.md
 * "Phase 2 as built"): the people they exchanged an "I'd like to puzzle with you" with at that event, either way, who
 * still have an open listing there (naming them asks them to close it - PartnerTeamedUp). Nobody blocked either way.
 * The one rule for the finder page's own row (GetPartnerListings) and ClosePartnerListingHandler's check.
 */
readonly final class PartnerTeamUpCandidates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * A `SELECT player_id …` of the candidates. Every argument is an SQL expression (`l.id`, `:ownerId`), never a value.
     */
    public static function sql(string $listingIdSql, string $ownerIdSql, string $competitionIdSql): string
    {
        return <<<SQL
SELECT candidate.player_id
FROM (
    SELECT got.from_player_id AS player_id
    FROM partner_request got
    WHERE got.listing_id = {$listingIdSql}
    UNION
    SELECT their.player_id
    FROM partner_request sent
    INNER JOIN partner_listing their ON their.id = sent.listing_id
    WHERE sent.from_player_id = {$ownerIdSql}
        AND their.competition_id = {$competitionIdSql}
) candidate
WHERE candidate.player_id <> {$ownerIdSql}
    AND EXISTS (
        SELECT 1 FROM partner_listing still_open
        WHERE still_open.competition_id = {$competitionIdSql}
            AND still_open.player_id = candidate.player_id
            AND still_open.closed_at IS NULL
    )
    AND NOT EXISTS (
        SELECT 1 FROM user_block team_up_block
        WHERE (team_up_block.blocker_id = candidate.player_id AND team_up_block.blocked_id = {$ownerIdSql})
            OR (team_up_block.blocker_id = {$ownerIdSql} AND team_up_block.blocked_id = candidate.player_id)
    )
SQL;
    }

    /**
     * @return list<string> lower-case player ids
     */
    public function of(PartnerListing $listing): array
    {
        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery(self::sql(':listingId', ':ownerId', ':competitionId'), [
                'listingId' => $listing->id->toString(),
                'ownerId' => $listing->player->id->toString(),
                'competitionId' => $listing->competition->id->toString(),
            ])
            ->fetchFirstColumn();

        return array_map(strtolower(...), $ids);
    }
}
