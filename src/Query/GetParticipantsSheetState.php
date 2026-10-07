<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetCompetition;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetPerson;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetPlace;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetPlayer;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetRegistration;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetRound;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetState;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetTeam;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The participants spreadsheet of one event (docs/features/competitions-management/participants-spreadsheet.md) - the
 * state its page embeds and its state endpoint answers (ParticipantsSheetState). Organiser tooling behind
 * COMPETITION_EDIT: every participant of the event is listed (removed ones too), with the organiser's own record.
 *
 * Privacy (contract O9): a participant row is never hidden - blocking must not make a participant unassignable - but
 * the linked MySpeedPuzzling profile's identity is withheld when the viewer blocks that player (HiddenPlayers - the
 * viewer's own blocks only, the blocked side must never be able to tell) or the profile is private to the viewer
 * (PrivateProfileAccess, the allow list included; nobody is private to themselves) - ParticipantsSheetPlayer.
 *
 * Seven statements for any event size: the version (first - GetParticipantsSheetVersion), the event, its rounds, its
 * people with their profiles, their places in rounds, the pairs/teams, and the rounds in which linked players have
 * times of their own (the results guard the sheet shows).
 */
readonly final class GetParticipantsSheetState
{
    public function __construct(
        private Connection $database,
        private GetParticipantsSheetVersion $getParticipantsSheetVersion,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
        private OfficialResultsSubscription $subscription,
        private UrlGeneratorInterface $urlGenerator,
        private ImageThumbnailTwigExtension $imageThumbnail,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function forCompetition(string $competitionId, null|string $viewerPlayerId): ParticipantsSheetState
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $competitionId = strtolower($competitionId);
        $now = $this->clock->now();

        // First, before the data: a change committed between the reads below makes the page fetch again at its next
        // version check, instead of the page believing it holds a newer state than it does
        $version = $this->getParticipantsSheetVersion->ofCompetition($competitionId);

        $event = $this->competitionRow($competitionId);
        $rounds = $this->rounds($competitionId, $event['is_online'], $event['location_country_code'], $event['series_country_code'], $now);
        $soloRounds = [];
        foreach ($rounds as $round) {
            if ($round->category === 'solo') {
                $soloRounds[$round->id] = true;
            }
        }

        $people = $this->people($competitionId, null, $event['registration_managed'], $viewerPlayerId);

        return new ParticipantsSheetState(
            serverNow: $now,
            version: $version,
            competition: new ParticipantsSheetCompetition(
                id: $competitionId,
                name: $event['name'],
                isOnline: $event['is_online'],
                registrationManaged: $event['registration_managed'],
                capacity: $event['capacity'],
                eventUrl: $this->eventUrl(new CompetitionReference(
                    name: $event['name'],
                    slug: $event['slug'],
                    seriesName: $event['series_name'],
                    seriesSlug: $event['series_slug'],
                )),
                editUrl: $this->urlGenerator->generate('edit_competition', ['competitionId' => $competitionId]),
            ),
            rounds: $rounds,
            people: $people,
            places: $this->places($competitionId, $soloRounds),
            teams: $this->teams($competitionId),
            mercure: $this->subscription->forParticipantsSheet(
                $competitionId,
                array_map(static fn (ParticipantsSheetRound $round): string => $round->id, $rounds),
            ),
        );
    }

    /**
     * One participant's row as the state has it - the answer of a registration action. Two statements.
     *
     * @throws CompetitionParticipantNotFound
     */
    public function person(string $competitionId, string $participantId, null|string $viewerPlayerId): ParticipantsSheetPerson
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($participantId) === false) {
            throw new CompetitionParticipantNotFound();
        }

        $people = $this->people(strtolower($competitionId), strtolower($participantId), null, $viewerPlayerId);

        return $people[0] ?? throw new CompetitionParticipantNotFound();
    }

    /**
     * @return array{name: string, slug: null|string, is_online: bool, registration_managed: bool, capacity: null|int, location_country_code: null|string, series_name: null|string, series_slug: null|string, series_country_code: null|string}
     * @throws CompetitionNotFound
     */
    private function competitionRow(string $competitionId): array
    {
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    c.name,
    c.slug,
    c.is_online,
    c.registration_managed,
    c.capacity,
    c.location_country_code,
    cs.name AS series_name,
    cs.slug AS series_slug,
    cs.location_country_code AS series_country_code
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
SQL,
            ['competitionId' => $competitionId],
        );

        if ($row === false) {
            throw new CompetitionNotFound();
        }

        /** @var array{name: string, slug: null|string, is_online: bool, registration_managed: bool, capacity: null|int, location_country_code: null|string, series_name: null|string, series_slug: null|string, series_country_code: null|string} $row */
        return $row;
    }

    private function eventUrl(CompetitionReference $reference): string
    {
        $routeName = $reference->routeName();

        return $routeName === null
            ? $this->urlGenerator->generate('events')
            : $this->urlGenerator->generate($routeName, $reference->routeParameters());
    }

    /**
     * By start, then name - the colours follow the schedule order (start, then id) like every other round list.
     *
     * @return list<ParticipantsSheetRound>
     */
    private function rounds(string $competitionId, bool $isOnline, null|string $countryCode, null|string $seriesCountryCode, DateTimeImmutable $now): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.category,
    cr.team_size,
    cr.starts_at,
    cr.timezone,
    cr.badge_background_color,
    cr.stopwatch_status,
    cr.stopwatch_started_at,
    cr.results_published_at,
    cr.table_numbers_off,
    puzzles.pieces_count
FROM competition_round cr
LEFT JOIN LATERAL (
    SELECT CASE WHEN COUNT(*) = 1 THEN MAX(puzzle.pieces_count) END AS pieces_count
    FROM competition_round_puzzle crp
    INNER JOIN puzzle ON puzzle.id = crp.puzzle_id
    WHERE crp.round_id = cr.id
) puzzles ON true
WHERE cr.competition_id = :competitionId
-- The position decides a round's automatic colour (RoundBadgeColor) - the same order as the event pages
ORDER BY cr.starts_at, cr.id
SQL,
            ['competitionId' => $competitionId],
        );

        $rounds = [];
        foreach ($rows as $schedulePosition => $row) {
            /** @var array{id: string, name: string, category: string, team_size: null|int, starts_at: string, timezone: null|string, badge_background_color: null|string, stopwatch_status: null|string, stopwatch_started_at: null|string, results_published_at: null|string, table_numbers_off: bool, pieces_count: null|int} $row */
            $roundId = strtolower($row['id']);
            $startsAt = new DateTimeImmutable($row['starts_at']);
            $color = RoundBadgeColor::background($row['badge_background_color'], $schedulePosition);

            $rounds[] = new ParticipantsSheetRound(
                id: $roundId,
                name: $row['name'],
                category: $row['category'],
                teamSize: $row['category'] === 'team' ? $row['team_size'] : null,
                startsAt: $startsAt,
                timezone: RoundTimezone::resolve($row['timezone'], $countryCode, $seriesCountryCode),
                started: $row['stopwatch_status'] !== null || $row['stopwatch_started_at'] !== null || $startsAt <= $now,
                color: $color,
                textColor: RoundBadgeColor::text($color),
                tableNumbersOff: $row['table_numbers_off'],
                piecesCount: $row['pieces_count'],
                resultsPublished: $row['results_published_at'] !== null,
                urls: [
                    'liveEntry' => $this->urlGenerator->generate('live_results', ['roundId' => $roundId]),
                    'resultsDesk' => $this->urlGenerator->generate('results_desk', ['roundId' => $roundId]),
                    // Nobody is seated at an online event
                    'seating' => $isOnline ? null : $this->urlGenerator->generate('round_seating', ['roundId' => $roundId]),
                    'edit' => $this->urlGenerator->generate('edit_competition_round', ['roundId' => $roundId]),
                ],
            );
        }

        usort($rounds, static fn (ParticipantsSheetRound $a, ParticipantsSheetRound $b): int => [$a->startsAt, $a->name, $a->id] <=> [$b->startsAt, $b->name, $b->id]);

        return $rounds;
    }

    /**
     * Every participant of the event (removed ones too) by name - or the one asked for. `$registrationManaged` null =
     * read it with the people.
     *
     * @return list<ParticipantsSheetPerson>
     */
    private function people(string $competitionId, null|string $participantId, null|bool $registrationManaged, null|string $viewerPlayerId): array
    {
        $onlyOne = $participantId !== null ? 'AND cp.id = :participantId' : '';
        $parameters = ['competitionId' => $competitionId];

        if ($participantId !== null) {
            $parameters['participantId'] = $participantId;
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cp.id,
    cp.name,
    cp.country,
    cp.external_id,
    cp.organizer_note,
    cp.source,
    cp.deleted_at,
    cp.connected_at,
    cp.registration_status,
    cp.registered_at,
    cp.paid_at,
    cp.checked_in_at,
    c.registration_managed,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country,
    p.avatar AS player_avatar,
    {$this->privateProfileAccess->sqlIsPrivate('p')} AS player_is_private
FROM competition_participant cp
INNER JOIN competition c ON c.id = cp.competition_id
LEFT JOIN player p ON p.id = cp.player_id
WHERE cp.competition_id = :competitionId
    {$onlyOne}
ORDER BY cp.name, cp.id
SQL,
            $parameters,
        );

        $playerIds = [];
        foreach ($rows as $row) {
            /** @var array{player_id: null|string} $row */
            if ($row['player_id'] !== null) {
                $playerIds[] = strtolower($row['player_id']);
            }
        }

        $resultRounds = $this->playerResultRounds($competitionId, $playerIds, $participantId !== null);
        $viewerPlayerId = $viewerPlayerId !== null ? strtolower($viewerPlayerId) : null;

        $people = [];
        foreach ($rows as $row) {
            /** @var array{id: string, name: string, country: null|string, external_id: null|string, organizer_note: null|string, source: string, deleted_at: null|string, connected_at: null|string, registration_status: null|string, registered_at: null|string, paid_at: null|string, checked_in_at: null|string, registration_managed: bool, player_id: null|string, player_name: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool} $row */
            $playerId = $row['player_id'] !== null ? strtolower($row['player_id']) : null;
            $managed = $registrationManaged ?? $row['registration_managed'];

            $people[] = new ParticipantsSheetPerson(
                id: strtolower($row['id']),
                name: $row['name'],
                country: $row['country'],
                externalId: $row['external_id'],
                note: $row['organizer_note'],
                source: $row['source'],
                removedAt: self::date($row['deleted_at']),
                connectedAt: self::date($row['connected_at']),
                player: $playerId !== null ? $this->player(
                    playerId: $playerId,
                    name: $row['player_name'],
                    code: (string) $row['player_code'],
                    country: $row['player_country'],
                    avatar: $row['player_avatar'],
                    privateToViewer: $row['player_is_private'] === true && $playerId !== $viewerPlayerId,
                ) : null,
                registration: $managed ? new ParticipantsSheetRegistration(
                    // A row without a status holds a spot - it reads as reserved
                    status: RegistrationStatus::tryFrom((string) $row['registration_status']) ?? RegistrationStatus::Reserved,
                    registeredAt: self::date($row['registered_at']),
                    paidAt: self::date($row['paid_at']),
                    checkedInAt: self::date($row['checked_in_at']),
                ) : null,
                playerResultRounds: $playerId !== null ? ($resultRounds[$playerId] ?? []) : [],
            );
        }

        return $people;
    }

    /**
     * `$privateToViewer`: the profile is private and the viewer is neither on its allow list (PrivateProfileAccess,
     * already in the SQL) nor the player themselves.
     */
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

    /**
     * The rounds of the event in which each linked player has a time - their own, or as a member of a pair/team (the
     * import's results guard, D11; suspicious times included - a time is a time there).
     *
     * @param list<string> $playerIds
     * @return array<string, list<string>> player id => round ids
     */
    private function playerResultRounds(string $competitionId, array $playerIds, bool $onlyThesePlayers): array
    {
        if ($playerIds === []) {
            return [];
        }

        $parameters = ['competitionId' => $competitionId];
        $trackers = '';
        $members = '';

        if ($onlyThesePlayers) {
            $parameters['playerId'] = $playerIds[0];
            $trackers = 'AND pst.player_id = :playerId';
            $members = 'AND ptm.player_id = :playerId';
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT pst.player_id, pst.competition_round_id AS round_id
FROM puzzle_solving_time pst
INNER JOIN competition_round cr ON cr.id = pst.competition_round_id
WHERE cr.competition_id = :competitionId
    {$trackers}
UNION
SELECT ptm.player_id, pst.competition_round_id AS round_id
FROM puzzle_solving_time pst
INNER JOIN competition_round cr ON cr.id = pst.competition_round_id
INNER JOIN puzzling_team_member ptm ON ptm.team_id = pst.puzzling_team_id
WHERE cr.competition_id = :competitionId
    AND ptm.player_id IS NOT NULL
    {$members}
ORDER BY round_id
SQL,
            $parameters,
        );

        $rounds = [];
        foreach ($rows as $row) {
            /** @var array{player_id: string, round_id: string} $row */
            $rounds[strtolower($row['player_id'])][] = strtolower($row['round_id']);
        }

        return $rounds;
    }

    /**
     * Every place of a person in a round of the event - removed people's too.
     *
     * @param array<string, true> $soloRounds
     * @return list<ParticipantsSheetPlace>
     */
    private function places(string $competitionId, array $soloRounds): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cpr.id,
    cpr.participant_id,
    cpr.round_id,
    cpr.team_id,
    cpr.table_number,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.result_did_not_start,
    cpr.qualified_at,
    cpr.result_entered_at,
    entered_by.name AS entered_by_name,
    entered_by.code AS entered_by_code
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id
LEFT JOIN player entered_by ON entered_by.id = cpr.result_entered_by_id
WHERE cr.competition_id = :competitionId
ORDER BY cpr.id
SQL,
            ['competitionId' => $competitionId],
        );

        $places = [];
        foreach ($rows as $row) {
            /** @var array{id: string, participant_id: string, round_id: string, team_id: null|string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, qualified_at: null|string, result_entered_at: null|string, entered_by_name: null|string, entered_by_code: null|string} $row */
            $roundId = strtolower($row['round_id']);
            // The official record of a pair/team round lives on the team
            $solo = isset($soloRounds[$roundId]);

            $places[] = new ParticipantsSheetPlace(
                id: strtolower($row['id']),
                participantId: strtolower($row['participant_id']),
                roundId: $roundId,
                teamId: $row['team_id'] !== null ? strtolower($row['team_id']) : null,
                table: $solo ? $row['table_number'] : null,
                result: $solo ? RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']) : RoundEntryResult::none(),
                qualified: $solo && $row['qualified_at'] !== null,
                enteredAt: $solo ? self::date($row['result_entered_at']) : null,
                enteredBy: $solo ? self::enteredBy($row['entered_by_name'], $row['entered_by_code']) : null,
            );
        }

        return $places;
    }

    /**
     * @return list<ParticipantsSheetTeam>
     */
    private function teams(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    ct.id,
    ct.round_id,
    ct.name,
    ct.table_number,
    ct.result_seconds,
    ct.result_pieces_placed,
    ct.result_did_not_start,
    ct.qualified_at,
    ct.result_entered_at,
    entered_by.name AS entered_by_name,
    entered_by.code AS entered_by_code
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id
LEFT JOIN player entered_by ON entered_by.id = ct.result_entered_by_id
WHERE cr.competition_id = :competitionId
-- Ids are UUIDv7 for teams created on the site: the order they were made in
ORDER BY ct.id
SQL,
            ['competitionId' => $competitionId],
        );

        $teams = [];
        foreach ($rows as $row) {
            /** @var array{id: string, round_id: string, name: null|string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, qualified_at: null|string, result_entered_at: null|string, entered_by_name: null|string, entered_by_code: null|string} $row */
            $teams[] = new ParticipantsSheetTeam(
                id: strtolower($row['id']),
                roundId: strtolower($row['round_id']),
                name: $row['name'],
                table: $row['table_number'],
                result: RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
                qualified: $row['qualified_at'] !== null,
                enteredAt: self::date($row['result_entered_at']),
                enteredBy: self::enteredBy($row['entered_by_name'], $row['entered_by_code']),
            );
        }

        return $teams;
    }

    private static function enteredBy(null|string $name, null|string $code): null|string
    {
        return $name ?? ($code !== null ? '#' . strtoupper($code) : null);
    }

    private static function date(null|string $value): null|DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }
}
