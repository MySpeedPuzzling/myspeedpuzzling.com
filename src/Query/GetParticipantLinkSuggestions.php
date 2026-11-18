<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetPlayer;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantNameKey;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\ParticipantLinkSuggestionTier;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Who the people of an event's list without a profile may be (docs/features/competitions-management/
 * participant-linking.md §7.2, §8.4, R2, R7, R10) - suggestions for the organiser, never a link by themselves. One
 * statement. For every participant on the list (not removed, waitlist included) who is not linked, the candidates of
 * three tiers, the best tier per player:
 *
 * 1. **Joined by themselves** - a self-joined row of the same event with the same name key (the row's name or its
 *    player's) and a country that does not contradict: the same person tapped "I'm going" and the organiser imported
 *    them too. Accepting merges the two (the self-joined row removed, its player linked to the organiser's row) - unless
 *    the self-joined row holds what a merge would throw away (`mergeRefusal`, R7: official data, round entries, a
 *    registration of an event managing registration - "Merge by hand"; a time its player added in a round of the event
 *    keeps the row too, the sheet refuses that removal itself: `has_result_in_event`, the group fails). The row is in the sheet
 *    already, so the profile is shown as the sheet shows it (O9 - masked when private to the viewer or blocked, D10).
 * 2. **Linked at another date** - a linked participant with the same name key at another event of the same series or
 *    organization.
 * 3. **Name and country** - a player with the same name key and the participant's country, only while at most
 *    COMMON_NAME_PLAYERS players have that name key (common names produce no suggestion). Uses `custom_player_name_key`.
 *
 * Never suggested: a player linked to another active participant of the event, a refused pair (D11, this event's log),
 * and for tiers 2-3 a player the viewer blocked (HiddenPlayers) or whose profile is private to them
 * (PrivateProfileAccess). E-mail addresses are never matched (D10). Signals per candidate: follows the event, its series
 * or its organization; the date they joined (tier 1); the edition they were linked at (tier 2 - public events only)
 * and whether that row's name was the organiser's (`nameFromOrganiser`: a self-joined row copies a free-text profile
 * name - a renamed profile must never be pre-ticked); whether the participant's country is the profile's. The one
 * pre-tick rule (R2: tier 2, one candidate, the organiser's name, matching country) is the dialog's. A withheld
 * profile (tier 1) carries no country match and no follow, and its merge is "Merge by hand" (`profile_hidden`).
 *
 * Shape for the planner: every name key is computed once per row (CTEs), tiers 1-2 are equality joins on the key
 * (hashed), tier 3 looks the person's key up in `custom_player_name_key` (a lateral per person, counting the key's
 * players with a window).
 *
 * sqlPeopleWithSuggestions() is the same set counted, as a scalar subquery - the toolkit hub's counter (§7.5).
 */
readonly final class GetParticipantLinkSuggestions
{
    public const int COMMON_NAME_PLAYERS = 3;

    /** A merge refused because the organiser may not see the profile (D10) */
    public const string MERGE_PROFILE_HIDDEN = 'profile_hidden';

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
        private UrlGeneratorInterface $urlGenerator,
        private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * People with at least one candidate, by name; their candidates by tier, then name.
     *
     * @return list<array{participant: string, name: string, country: null|string, candidates: list<array{tier: string, player: ParticipantsSheetPlayer, countryMatches: null|bool, follows: null|bool, nameFromOrganiser: null|bool, joinedAt: null|string, linkedAt: null|array{name: string, date: null|string}, selfJoined: null|array{participant: string, mergeRefusal: null|string}}>}>
     */
    public function of(string $competitionId): array
    {
        $suggestions = $this->sqlSuggestions();
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('p');
        $officialData = OfficialResultsGuard::sqlParticipantHoldsOfficialData('s');
        $paid = RegistrationStatus::Paid->value;
        $waitlisted = RegistrationStatus::Waitlisted->value;

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
{$suggestions}
SELECT
    r.participant_id,
    u.name AS participant_name,
    u.country AS participant_country,
    r.player_id,
    r.tier,
    r.self_joined_id,
    r.name_from_organiser,
    r.edition_name,
    r.edition_date,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country,
    p.avatar AS player_avatar,
    {$isPrivate} AS player_is_private,
    COALESCE(u.country IS NOT NULL AND p.country = u.country, false) AS country_matches,
    EXISTS (
        SELECT 1 FROM followed_competition fc
        WHERE fc.player_id = r.player_id
            AND (fc.competition_id = e.id OR fc.series_id = e.series_id OR fc.organization_id = e.organization_id)
    ) AS follows,
    s.connected_at AS joined_at,
    CASE
        WHEN s.id IS NULL THEN NULL
        WHEN {$officialData} THEN 'official_data'
        WHEN EXISTS (SELECT 1 FROM competition_participant_round entry WHERE entry.participant_id = s.id) THEN 'round_entries'
        WHEN e.registration_managed AND s.registration_status = '{$paid}' THEN 'paid'
        WHEN e.registration_managed AND s.registration_status = '{$waitlisted}' THEN 'waitlisted'
        WHEN e.registration_managed AND s.registration_status IS NOT NULL THEN 'registered'
    END AS merge_refusal
FROM suggestion r
INNER JOIN unlinked u ON u.id = r.participant_id
INNER JOIN player p ON p.id = r.player_id
LEFT JOIN competition_participant s ON s.id = r.self_joined_id
CROSS JOIN this_event e
ORDER BY lower(u.name), u.id, r.tier, lower(COALESCE(p.name, p.code)), p.id
SQL,
            ['competitionId' => $competitionId],
        );

        $people = [];

        foreach ($rows as $row) {
            /** @var array{participant_id: string, participant_name: string, participant_country: null|string, player_id: string, tier: int|string, self_joined_id: null|string, name_from_organiser: null|bool, edition_name: null|string, edition_date: null|string, player_name: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: bool, country_matches: bool, follows: bool, joined_at: null|string, merge_refusal: null|string} $row */
            $participantId = $row['participant_id'];
            $tier = ParticipantLinkSuggestionTier::fromNumber((int) $row['tier']);

            $people[$participantId] ??= [
                'participant' => $participantId,
                'name' => $row['participant_name'],
                'country' => $row['participant_country'],
                'candidates' => [],
            ];

            $player = $this->player($row['player_id'], $row['player_name'], (string) $row['player_code'], $row['player_country'], $row['player_avatar'], $row['player_is_private']);
            // A profile withheld from the organiser (D10 - only tier 1 keeps one): nothing about it is said - no country
            // compared, no follow - and it is never merged blind, without the #code to check: "Merge by hand"
            $withheld = $player->visible === false;

            $people[$participantId]['candidates'][] = [
                'tier' => $tier->value,
                'player' => $player,
                'countryMatches' => $withheld ? null : $row['country_matches'],
                'follows' => $withheld ? null : $row['follows'],
                // Tier 2: the earlier row's name is the organiser's, not copied from a profile by a self-join (R2)
                'nameFromOrganiser' => $row['name_from_organiser'],
                'joinedAt' => self::date($row['joined_at']),
                'linkedAt' => $row['edition_name'] !== null ? ['name' => $row['edition_name'], 'date' => self::date($row['edition_date'])] : null,
                'selfJoined' => $row['self_joined_id'] !== null ? [
                    'participant' => $row['self_joined_id'],
                    'mergeRefusal' => $row['merge_refusal'] ?? ($withheld ? self::MERGE_PROFILE_HIDDEN : null),
                ] : null,
            ];
        }

        return array_values($people);
    }

    /**
     * How many people of the event's list have at least one suggestion - one statement, no player read beyond the match
     * (the import's summary). sqlPeopleWithSuggestions() is the same count as a scalar subquery taking `:competitionId`,
     * for a statement of the caller's (GetParticipantLinkSummary: the hub's counter costs no statement of its own).
     */
    public function peopleWithSuggestions(string $competitionId): int
    {
        $count = $this->database->fetchOne('SELECT ' . $this->sqlPeopleWithSuggestions(), ['competitionId' => $competitionId]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The same as a subquery (see peopleWithSuggestions()).
     */
    public function sqlPeopleWithSuggestions(): string
    {
        return "({$this->sqlSuggestions()} SELECT COUNT(DISTINCT suggestion.participant_id) FROM suggestion)";
    }

    /**
     * The common part: `WITH this_event, unlinked, editions, linked_before, candidate, suggestion` - `suggestion` = one row
     * per participant and player, the best tier, the exclusions applied.
     */
    private function sqlSuggestions(): string
    {
        $key = ParticipantNameKey::sql(...);
        $selfJoined = ParticipantSource::SelfJoined->value;
        $refusing = GetRefusedParticipantLinks::sqlRefusingActions();
        $commonName = self::COMMON_NAME_PLAYERS;
        $publiclyVisible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        // Tiers 2-3 name somebody the sheet does not show yet: only a profile the viewer may see (D10)
        $visible = $this->privateProfileAccess->sqlIsPublic('candidate_player') . $this->hiddenPlayers->sqlExclude('candidate_player.id');

        return <<<SQL
WITH this_event AS (
    SELECT c.id, c.series_id, COALESCE(c.organization_id, cs.organization_id) AS organization_id, c.registration_managed
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    WHERE c.id = :competitionId
),
unlinked AS (
    SELECT u.id, u.name, u.country, {$key('u.name')} AS name_key
    FROM competition_participant u
    WHERE u.competition_id = :competitionId
        AND u.deleted_at IS NULL
        AND u.player_id IS NULL
),
editions AS (
    -- The other public events of the same series or organization (a draft is nobody's "other date" yet)
    SELECT c.id, c.name, c.date_from
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    CROSS JOIN this_event e
    WHERE c.id <> e.id
        AND {$publiclyVisible}
        AND ((e.series_id IS NOT NULL AND c.series_id = e.series_id)
            OR (e.organization_id IS NOT NULL AND COALESCE(c.organization_id, cs.organization_id) = e.organization_id))
),
linked_before AS (
    -- `name_from_organiser`: the row's name is the organiser's - a self-joined row copied it from a free-text profile
    -- name, so only the organiser's name may pre-tick (R2)
    SELECT o.player_id, o.competition_id, {$key('o.name')} AS name_key, o.source <> '{$selfJoined}' AS name_from_organiser
    FROM competition_participant o
    INNER JOIN editions ON editions.id = o.competition_id
    WHERE o.deleted_at IS NULL
        AND o.player_id IS NOT NULL
),
self_rows AS (
    -- The event's self-joined rows, both name keys computed once: the row's name and its player's profile name
    SELECT s.id, s.player_id, COALESCE(s.country, sp.country) AS country, {$key('s.name')} AS own_key, {$key('sp.name')} AS profile_key
    FROM competition_participant s
    INNER JOIN player sp ON sp.id = s.player_id
    WHERE s.competition_id = :competitionId
        AND s.deleted_at IS NULL
        AND s.source = '{$selfJoined}'
),
self_keys AS (
    SELECT id, player_id, country, own_key AS name_key FROM self_rows
    UNION
    SELECT id, player_id, country, profile_key FROM self_rows WHERE profile_key IS NOT NULL
),
candidate AS (
    -- 1. Joined by themselves: the same person's self-joined row of this event (an equality join - hashed)
    SELECT u.id AS participant_id, sk.player_id, 1 AS tier, sk.id AS self_joined_id, CAST(NULL AS UUID) AS edition_id, CAST(NULL AS BOOLEAN) AS name_from_organiser
    FROM unlinked u
    INNER JOIN self_keys sk ON sk.name_key = u.name_key
    WHERE u.country IS NULL OR sk.country IS NULL OR sk.country = u.country
    UNION ALL
    -- 2. Linked at another date of the same series or organization
    SELECT u.id, lb.player_id, 2, NULL, lb.competition_id, lb.name_from_organiser
    FROM unlinked u
    INNER JOIN linked_before lb ON lb.name_key = u.name_key
    UNION ALL
    -- 3. Name and country - not for a common name: the players of the person's name key (custom_player_name_key), how
    -- many they are counted over all of them, then the country
    SELECT u.id, same_name.id, 3, NULL, NULL, NULL
    FROM unlinked u
    CROSS JOIN LATERAL (
        SELECT p.id, p.country, COUNT(*) OVER () AS players
        FROM player p
        WHERE {$key('p.name')} = u.name_key
    ) same_name
    WHERE u.country IS NOT NULL
        AND u.name_key <> ''
        AND same_name.country = u.country
        AND same_name.players <= {$commonName}
),
suggestion AS (
    SELECT DISTINCT ON (candidate.participant_id, candidate.player_id)
        candidate.participant_id, candidate.player_id, candidate.tier, candidate.self_joined_id,
        candidate.name_from_organiser,
        editions.name AS edition_name, editions.date_from AS edition_date
    FROM candidate
    INNER JOIN player candidate_player ON candidate_player.id = candidate.player_id
    LEFT JOIN editions ON editions.id = candidate.edition_id
    WHERE NOT EXISTS (
            -- Linked to another active participant of the event (for tier 1: to another row than the self-joined one)
            SELECT 1 FROM competition_participant linked
            WHERE linked.competition_id = :competitionId
                AND linked.deleted_at IS NULL
                AND linked.player_id = candidate.player_id
                AND linked.id IS DISTINCT FROM candidate.self_joined_id
        )
        AND NOT EXISTS (
            SELECT 1 FROM participant_link_log refusal
            WHERE refusal.competition_id = :competitionId
                AND refusal.participant_id = candidate.participant_id
                AND refusal.player_id = candidate.player_id
                AND refusal.action IN ({$refusing})
        )
        AND (candidate.tier = 1 OR ({$visible}))
    ORDER BY candidate.participant_id, candidate.player_id, candidate.tier, candidate.name_from_organiser DESC NULLS LAST, editions.date_from DESC NULLS LAST
)
SQL;
    }

    private function player(string $playerId, null|string $name, string $code, null|string $country, null|string $avatar, bool $privateToViewer): ParticipantsSheetPlayer
    {
        if ($privateToViewer || $this->hiddenPlayers->isHidden($playerId)) {
            return ParticipantsSheetPlayer::withheld($playerId);
        }

        return ParticipantsSheetPlayer::visible(
            id: $playerId,
            name: $name,
            code: $code,
            country: $country,
            avatar: $avatar !== null ? $this->imageThumbnail->thumbnailUrl($avatar, 'puzzle_small') : null,
            profileUrl: $this->urlGenerator->generate('player_profile', ['playerId' => $playerId]),
        );
    }

    private static function date(null|string $value): null|string
    {
        return $value !== null ? (new DateTimeImmutable($value))->format(DateTimeImmutable::ATOM) : null;
    }
}
