<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PartnerCoMember;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
use SpeedPuzzling\Web\Results\PartnerFinderTeaser;
use SpeedPuzzling\Web\Results\PartnerListingRow;
use SpeedPuzzling\Web\Results\PartnerListings;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerStrength;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SkillTier;

/**
 * The partner finder page (docs/features/partner-finder.md, "Performance budgets"): ONE statement for a signed-in viewer,
 * ONE for a guest - each answers "does the event have a finder" too (no row = no finder, PartnerFinderEligibility's rule).
 *
 * Signed in: the open listings the viewer may see - blocks hide both ways (HiddenPlayers for the viewer's own blocks, an
 * explicit user_block lookup for the listers who block the viewer), a private player is masked by PrivateProfileAccess
 * and then shows no numbers - the own listing first, then the newest, at most LIMIT. Speed comes from the precomputed
 * community_player_stats, the MSP Rating from player_elo (500 pieces) - both left out for a player who opted out of
 * rankings (the rating) - and the skill tier only when the viewer is a member (else NULL in SQL, the table is not read).
 * Guests get counts and faces only.
 */
readonly final class GetPartnerListings
{
    public const int LIMIT = 100;

    // The speed numbers and the tier are about 500-piece puzzles, as on the player card
    public const int PIECES_COUNT = 500;

    public const int TEASER_FACES = 8;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
        private PartnerFinderEligibility $eligibility,
    ) {
    }

    /**
     * null = the event has no partner finder.
     */
    public function forEvent(string $competitionId, string $viewerId, bool $viewerIsMember): null|PartnerListings
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($viewerId) === false) {
            return null;
        }

        $event = $this->eventCte();
        $notHidden = $this->hiddenPlayers->sqlExclude('pl.player_id');
        $notHiddenSender = $this->hiddenPlayers->sqlExclude('got.from_player_id');
        $notHiddenCoMember = $this->hiddenPlayers->sqlExclude('cm.id');
        // The viewer's own row (and the viewer as somebody's co-member) is never masked, private or not - see the SQL
        $privateToViewer = $this->privateProfileAccess->sqlIsPrivate('p');
        $coMemberPrivate = $this->privateProfileAccess->sqlIsPrivate('cm');
        $registered = GetMarketplaceEvents::sqlPlayerGoing('event.id', 'l.player_id');
        $limit = self::LIMIT;

        // Members only: anybody else never reads player_skill
        $skillTier = $viewerIsMember
            ? 'CASE WHEN l.private_to_viewer OR l.ranking_opted_out THEN NULL ELSE skill.skill_tier END'
            : 'NULL';
        $skillJoin = $viewerIsMember
            ? 'LEFT JOIN player_skill skill ON skill.player_id = l.player_id AND skill.pieces_count = :piecesCount'
            : '';

        $query = <<<SQL
WITH {$event},
listing AS (
    SELECT
        pl.id,
        pl.player_id,
        pl.group_size,
        pl.looking_for,
        pl.needed,
        pl.co_member_ids,
        pl.goal,
        pl.strengths,
        pl.custom_strengths,
        pl.languages,
        pl.note,
        pl.created_at,
        p.name AS player_name,
        p.code AS player_code,
        p.country AS player_country,
        p.avatar AS player_avatar,
        p.ranking_opted_out,
        (pl.player_id <> :viewerId AND {$privateToViewer}) AS private_to_viewer,
        pl.player_id = :viewerId AS is_own,
        COUNT(*) OVER () AS total_open
    FROM partner_listing pl
    INNER JOIN event ON event.id = pl.competition_id
    INNER JOIN player p ON p.id = pl.player_id
    WHERE pl.closed_at IS NULL
        {$notHidden}
        AND NOT EXISTS (SELECT 1 FROM user_block lister_block WHERE lister_block.blocker_id = pl.player_id AND lister_block.blocked_id = :viewerId)
    ORDER BY pl.player_id = :viewerId DESC, pl.created_at DESC, pl.id DESC
    LIMIT {$limit}
)
SELECT
    event.registration_managed,
    event.categories,
    event.team_size,
    (SELECT viewer_stats.median500_seconds FROM community_player_stats viewer_stats WHERE viewer_stats.player_id = :viewerId) AS viewer_median500,
    l.id,
    l.player_id,
    l.group_size,
    l.looking_for,
    l.needed,
    l.goal,
    l.strengths,
    l.custom_strengths,
    l.languages,
    l.note,
    l.created_at,
    l.player_name,
    l.player_code,
    l.player_country,
    CASE WHEN l.private_to_viewer THEN NULL ELSE l.player_avatar END AS player_avatar,
    l.private_to_viewer,
    l.is_own,
    l.ranking_opted_out,
    l.total_open,
    CASE WHEN l.private_to_viewer THEN NULL ELSE stats.median500_seconds END AS median500_seconds,
    CASE WHEN l.private_to_viewer THEN NULL ELSE stats.median1000_seconds END AS median1000_seconds,
    CASE WHEN l.private_to_viewer THEN NULL ELSE COALESCE(stats.solved_total, 0) END AS solved_total,
    CASE WHEN l.private_to_viewer OR l.ranking_opted_out THEN NULL ELSE elo.elo_rating END AS elo_rating,
    {$skillTier} AS skill_tier,
    CASE WHEN event.registration_managed AND l.id IS NOT NULL THEN {$registered} END AS registered,
    EXISTS (SELECT 1 FROM partner_request sent WHERE sent.listing_id = l.id AND sent.from_player_id = :viewerId) AS sent,
    CASE WHEN l.is_own THEN (SELECT COUNT(*) FROM partner_request got WHERE got.listing_id = l.id {$notHiddenSender}) ELSE 0 END AS requests_count,
    (
        SELECT json_agg(json_build_object(
            'id', cm.id,
            'name', cm.name,
            'code', cm.code,
            'country', cm.country,
            'avatar', cm.avatar,
            'masked', (cm.id <> :viewerId AND {$coMemberPrivate})
        ) ORDER BY member.position)
        FROM jsonb_array_elements_text(l.co_member_ids) WITH ORDINALITY AS member(id, position)
        INNER JOIN player cm ON cm.id = member.id::uuid
        WHERE NOT EXISTS (SELECT 1 FROM user_block member_block WHERE member_block.blocker_id = cm.id AND member_block.blocked_id = :viewerId)
            {$notHiddenCoMember}
    ) AS co_members
FROM event
LEFT JOIN listing l ON true
LEFT JOIN community_player_stats stats ON stats.player_id = l.player_id
LEFT JOIN player_elo elo ON elo.player_id = l.player_id AND elo.pieces_count = :piecesCount
{$skillJoin}
ORDER BY l.is_own DESC NULLS LAST, l.created_at DESC NULLS LAST, l.id DESC
SQL;

        $parameters = [
            'competitionId' => $competitionId,
            'viewerId' => $viewerId,
            'piecesCount' => self::PIECES_COUNT,
            ...$this->eligibility->todayParameter(),
        ];

        /**
         * @var list<array{
         *     registration_managed: bool,
         *     categories: string,
         *     team_size: null|int|string,
         *     viewer_median500: null|int|string,
         *     id: null|string,
         *     player_id: null|string,
         *     group_size: null|int|string,
         *     looking_for: null|string,
         *     needed: null|int|string,
         *     goal: null|string,
         *     strengths: null|string,
         *     custom_strengths: null|string,
         *     languages: null|string,
         *     note: null|string,
         *     created_at: null|string,
         *     player_name: null|string,
         *     player_code: null|string,
         *     player_country: null|string,
         *     player_avatar: null|string,
         *     private_to_viewer: null|bool,
         *     is_own: null|bool,
         *     ranking_opted_out: null|bool,
         *     total_open: null|int|string,
         *     median500_seconds: null|int|string,
         *     median1000_seconds: null|int|string,
         *     solved_total: null|int|string,
         *     elo_rating: null|float|string,
         *     skill_tier: null|int|string,
         *     registered: null|bool,
         *     sent: bool,
         *     requests_count: int|string,
         *     co_members: null|string,
         * }> $rows
         */
        $rows = $this->database->executeQuery($query, $parameters)->fetchAllAssociative();

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $listings = [];

        foreach ($rows as $row) {
            if ($row['id'] === null || $row['player_id'] === null || $row['created_at'] === null) {
                continue;
            }

            $listings[] = new PartnerListingRow(
                listingId: $row['id'],
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: (string) $row['player_code'],
                countryCode: $row['player_country'],
                avatar: $row['player_avatar'],
                private: $row['private_to_viewer'] === true,
                own: $row['is_own'] === true,
                groupSize: (int) $row['group_size'],
                lookingFor: self::kinds($row['looking_for']),
                needed: $row['needed'] !== null ? (int) $row['needed'] : null,
                coMembers: self::coMembers($row['co_members']),
                goal: $row['goal'] !== null ? PartnerGoal::tryFrom($row['goal']) : null,
                strengths: array_values(array_filter(array_map(PartnerStrength::tryFrom(...), self::strings($row['strengths'])))),
                customStrengths: self::strings($row['custom_strengths']),
                languages: self::strings($row['languages']),
                note: $row['note'],
                createdAt: new DateTimeImmutable($row['created_at']),
                median500Seconds: $row['median500_seconds'] !== null ? (int) $row['median500_seconds'] : null,
                median1000Seconds: $row['median1000_seconds'] !== null ? (int) $row['median1000_seconds'] : null,
                solvedTotal: $row['solved_total'] !== null ? (int) $row['solved_total'] : null,
                mspRating: $row['elo_rating'] !== null ? (int) round((float) $row['elo_rating'] * 1000) : null,
                skillTier: $row['skill_tier'] !== null ? SkillTier::tryFrom((int) $row['skill_tier']) : null,
                rankingOptedOut: $row['ranking_opted_out'] === true,
                registered: $row['registered'],
                sent: $row['sent'],
                requestsCount: (int) $row['requests_count'],
            );
        }

        return new PartnerListings(
            offer: self::offer($first['categories'], $first['team_size']),
            registrationManaged: $first['registration_managed'],
            viewerMedian500Seconds: $first['viewer_median500'] !== null ? (int) $first['viewer_median500'] : null,
            rows: $listings,
            totalOpen: $listings === [] ? 0 : (int) $first['total_open'],
        );
    }

    /**
     * What a guest sees (D5): counts per kind and country and a few faces - no names, notes or links. null = the event has
     * no partner finder. Shared-cacheable: nobody is hidden or revealed for a guest.
     */
    public function teaserForEvent(string $competitionId): null|PartnerFinderTeaser
    {
        if (Uuid::isValid($competitionId) === false) {
            return null;
        }

        $event = $this->eventCte();
        $notHidden = $this->hiddenPlayers->sqlExclude('pl.player_id');
        $privateToViewer = $this->privateProfileAccess->sqlIsPrivate('p');
        $faces = self::TEASER_FACES;

        $query = <<<SQL
WITH {$event},
open_listing AS (
    SELECT
        pl.id,
        pl.player_id,
        pl.group_size,
        pl.looking_for,
        pl.created_at,
        p.country,
        p.avatar,
        {$privateToViewer} AS private_to_viewer
    FROM partner_listing pl
    INNER JOIN event ON event.id = pl.competition_id
    INNER JOIN player p ON p.id = pl.player_id
    WHERE pl.closed_at IS NULL
        {$notHidden}
)
SELECT
    event.categories,
    event.team_size,
    (SELECT COUNT(*) FROM open_listing) AS total,
    (SELECT COUNT(*) FROM open_listing o WHERE o.group_size = 1 AND o.looking_for @> '["pair"]'::jsonb) AS pairs,
    (SELECT COUNT(*) FROM open_listing o WHERE o.group_size = 1 AND o.looking_for @> '["team"]'::jsonb) AS teams,
    (SELECT COUNT(*) FROM open_listing o WHERE o.group_size > 1) AS groups,
    (
        SELECT json_agg(json_build_object('country', per_country.country, 'count', per_country.listings) ORDER BY per_country.listings DESC, per_country.country)
        FROM (
            SELECT LOWER(o.country) AS country, COUNT(*) AS listings
            FROM open_listing o
            WHERE o.country IS NOT NULL AND o.country <> ''
            GROUP BY LOWER(o.country)
        ) per_country
    ) AS countries,
    (
        SELECT json_agg(json_build_object(
            'id', newest.player_id,
            'country', newest.country,
            'avatar', CASE WHEN newest.private_to_viewer THEN NULL ELSE newest.avatar END,
            'masked', newest.private_to_viewer
        ) ORDER BY newest.created_at DESC, newest.id DESC)
        FROM (SELECT * FROM open_listing o ORDER BY o.created_at DESC, o.id DESC LIMIT {$faces}) newest
    ) AS faces
FROM event
SQL;

        /**
         * @var false|array{
         *     categories: string,
         *     team_size: null|int|string,
         *     total: int|string,
         *     pairs: int|string,
         *     teams: int|string,
         *     groups: int|string,
         *     countries: null|string,
         *     faces: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                ...$this->eligibility->todayParameter(),
            ])
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $countries = [];

        /** @var list<array{country: string, count: int}> $countryRows */
        $countryRows = $row['countries'] !== null ? json_decode($row['countries'], true, flags: JSON_THROW_ON_ERROR) : [];

        foreach ($countryRows as $countryRow) {
            $countries[$countryRow['country']] = (int) $countryRow['count'];
        }

        /** @var list<array{id: string, country: null|string, avatar: null|string, masked: null|bool}> $faceRows */
        $faceRows = $row['faces'] !== null ? json_decode($row['faces'], true, flags: JSON_THROW_ON_ERROR) : [];

        return new PartnerFinderTeaser(
            offer: self::offer($row['categories'], $row['team_size']),
            total: (int) $row['total'],
            pairs: (int) $row['pairs'],
            teams: (int) $row['teams'],
            groups: (int) $row['groups'],
            countries: $countries,
            faces: array_map(static fn (array $face): array => [
                'playerId' => $face['id'],
                'countryCode' => $face['country'],
                'avatar' => $face['masked'] === true ? null : $face['avatar'],
                'masked' => $face['masked'] === true,
            ], $faceRows),
        );
    }

    /**
     * `event AS (…)`: the event when it has a partner finder (PartnerFinderEligibility::SQL_QUALIFIES - publicly visible,
     * drafts never), with what its rounds offer. Binds :competitionId and :marketplace_today.
     */
    private function eventCte(): string
    {
        $qualifies = PartnerFinderEligibility::SQL_QUALIFIES;

        return <<<SQL
event AS (
    SELECT
        c.id,
        c.registration_managed,
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
)
SQL;
    }

    private static function offer(string $categoriesJson, null|int|string $teamSize): PartnerFinderOffer
    {
        $categories = array_values(array_filter(array_map(RoundCategory::tryFrom(...), self::strings($categoriesJson))));

        return PartnerFinderOffer::fromRoundCategories($categories, $teamSize !== null ? (int) $teamSize : null);
    }

    /**
     * @return list<string>
     */
    private static function strings(null|string $json): array
    {
        if ($json === null) {
            return [];
        }

        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (is_array($values) === false) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * @return list<PartnerKind>
     */
    private static function kinds(null|string $json): array
    {
        return array_values(array_filter(array_map(PartnerKind::tryFrom(...), self::strings($json))));
    }

    /**
     * @return list<PartnerCoMember>
     */
    private static function coMembers(null|string $json): array
    {
        if ($json === null) {
            return [];
        }

        /** @var list<array{id: string, name: null|string, code: string, country: null|string, avatar: null|string, masked: null|bool}> $rows */
        $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_map(PartnerCoMember::fromJson(...), $rows);
    }
}
