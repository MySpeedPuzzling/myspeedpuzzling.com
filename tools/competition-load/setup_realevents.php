<?php

declare(strict_types=1);

/**
 * `setup.php realevents` (README "Real events"): included by setup.php after its refusal guard and the kernel boot -
 * uses its $entityManager, $connection, $secret, $now and helpers (id(), COMPETITION, ORGANISER, PUZZLES, LIFETIME).
 *
 * Three rounds of the Results Cup running at once:
 *   round 1 "Load Solo"    solo, pick 2 of 2 ("Solve all 2"), 350 entrants "Load Tester 0001..0350" at tables 1..350
 *   round 2 "Load Teams A" team of 4, total, 150 teams at tables 1..150 (members = testers 0351..0950)
 *   round 3 "Load Teams B" team of 4, total, 150 teams at tables 1..150 (members = testers 0951..1550)
 * 18 referees "Load Judge 1..18" (k6 gives each round six), 6 displays (per round a live-board screen and a
 * leaderboard overlay pinned to it). Judges save solo tables 1..250 and teams 1..110; the proposers are the solo
 * entrants at 251..350 and one member of each team at 111..150.
 */

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitorHeartbeatToken;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayPresets;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

const RE_SOLO = 350;
const RE_TEAMS = 150;
const RE_TEAM_SIZE = 4;
const RE_JUDGES = 18;
const RE_SOLO_JUDGED = 250;
const RE_TEAMS_JUDGED = 110;
const RE_PEOPLE = RE_SOLO + 2 * RE_TEAMS * RE_TEAM_SIZE;

$rounds = [
    ['id' => id('0000', 1), 'name' => 'Load Solo', 'slug' => 'load-solo', 'category' => 'solo', 'format' => 'pick', 'toSolve' => 2, 'teamSize' => null, 'puzzles' => [PUZZLES[0], PUZZLES[1]]],
    ['id' => id('0000', 2), 'name' => 'Load Teams A', 'slug' => 'load-teams-a', 'category' => 'team', 'format' => 'total', 'toSolve' => null, 'teamSize' => RE_TEAM_SIZE, 'puzzles' => [PUZZLES[2]]],
    ['id' => id('0000', 3), 'name' => 'Load Teams B', 'slug' => 'load-teams-b', 'category' => 'team', 'format' => 'total', 'toSolve' => null, 'teamSize' => RE_TEAM_SIZE, 'puzzles' => ['018d0003-0000-0000-0000-000000000014']],
];
$roundIds = array_column($rounds, 'id');

// --- the accounts (created once, kept between runs)
$people = [];
for ($i = 1; $i <= RE_PEOPLE + RE_JUDGES; $i++) {
    $judge = $i > RE_PEOPLE;
    $n = $judge ? $i - RE_PEOPLE : $i;
    $playerId = id($judge ? '0005' : '0004', $n);
    $userId = 'load|' . $playerId;
    $people[$playerId] = ['userId' => $userId, 'judge' => $judge, 'n' => $n];

    if ($entityManager->find(Player::class, $playerId) !== null) {
        continue;
    }

    $account = new UserAccount(Uuid::uuid7(), $userId, 'load-' . substr($playerId, -6) . ($judge ? '-judge' : '') . '@example.com', $now);
    $account->markEmailVerified($now);
    $entityManager->persist($account);
    $name = $judge ? 'Load Judge ' . $n : 'Load Tester ' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    $entityManager->persist(new Player(Uuid::fromString($playerId), 'load' . ($judge ? 'j' : 'c') . $n, $userId, $name, $now->modify('-30 days')));

    if ($i % 200 === 0) {
        $entityManager->flush();
        $entityManager->clear();
    }
}
$entityManager->flush();
$entityManager->clear();

// --- the rounds, entrants, teams and referees, anew
$connection->transactional(static function (\Doctrine\DBAL\Connection $connection) use ($rounds, $roundIds): void {
    $types = [\Doctrine\DBAL\ArrayParameterType::STRING];
    $connection->executeStatement("DELETE FROM competition_display WHERE id::text LIKE '018d0098-0000-0000-0008-%'");
    foreach (['competition_time_proposal', 'round_result_change_receipt', 'competition_participant_round', 'competition_team', 'competition_round_puzzle'] as $table) {
        $connection->executeStatement("DELETE FROM $table WHERE round_id IN (?)", [$roundIds], $types);
    }
    $connection->executeStatement('DELETE FROM puzzle_solving_time WHERE competition_round_id IN (?)', [$roundIds], $types);
    $connection->executeStatement('DELETE FROM competition_round WHERE id IN (?)', [$roundIds], $types);
    $connection->executeStatement("DELETE FROM competition_participant WHERE id::text LIKE '018d0098-0000-0000-0001-%'");
    $connection->executeStatement("DELETE FROM competition_referee WHERE id::text LIKE '018d0098-0000-0000-0003-%'");

    $position = 0;
    foreach ($rounds as $round) {
        $connection->executeStatement(<<<'SQL'
INSERT INTO competition_round (id, competition_id, name, slug, category, puzzle_format, puzzles_to_solve, team_size, minutes_limit,
                               starts_at, timezone, stopwatch_status, stopwatch_started_at, stopwatch_first_started_at,
                               table_numbers_off, reveal_delay_minutes, start_time_known)
VALUES (:round, :competition, :name, :slug, :category, :format, :toSolve, :teamSize, 600,
        (now() AT TIME ZONE 'UTC') - interval '10 minutes', 'Europe/Prague',
        'running', (now() AT TIME ZONE 'UTC') - interval '10 minutes', (now() AT TIME ZONE 'UTC') - interval '10 minutes',
        false, 10, true)
SQL, ['round' => $round['id'], 'competition' => COMPETITION, 'name' => $round['name'], 'slug' => $round['slug'], 'category' => $round['category'],
            'format' => $round['format'], 'toSolve' => $round['toSolve'], 'teamSize' => $round['teamSize']]);

        foreach ($round['puzzles'] as $index => $puzzleId) {
            $connection->executeStatement(
                "INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, position, hide_until_round_starts, reveal_mode, hides_everywhere)
                 VALUES (?, ?, ?, ?, false, 'automatic', false)",
                [id('0006', ++$position), $round['id'], $puzzleId, $index + 1],
            );
        }
    }

    // Every person of the three rounds: a participant of the event, linked to their own account a day ago
    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_participant (id, competition_id, name, country, source, player_id, connected_at)
SELECT ('018d0098-0000-0000-0001-' || lpad(i::text, 12, '0'))::uuid, :competition,
       'Load Tester ' || lpad(i::text, 4, '0'), (ARRAY['cz', 'de', 'sk', 'us', 'pl'])[1 + i % 5], 'manual',
       ('018d0098-0000-0000-0004-' || lpad(i::text, 12, '0'))::uuid, (now() AT TIME ZONE 'UTC') - interval '1 day'
FROM generate_series(1, :people) AS i
SQL, ['competition' => COMPETITION, 'people' => RE_PEOPLE]);

    // Solo: tables 1..350
    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_participant_round (id, participant_id, round_id, table_number, result_did_not_start)
SELECT ('018d0098-0000-0000-0002-' || lpad(i::text, 12, '0'))::uuid,
       ('018d0098-0000-0000-0001-' || lpad(i::text, 12, '0'))::uuid, :round, i, false
FROM generate_series(1, :solo) AS i
SQL, ['round' => $rounds[0]['id'], 'solo' => RE_SOLO]);

    // Teams: 1..150 in round 2, 151..300 in round 3 - each at table ((t-1) % 150) + 1 with 4 members
    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_team (id, round_id, name, table_number, result_did_not_start)
SELECT ('018d0098-0000-0000-0007-' || lpad(t::text, 12, '0'))::uuid, CASE WHEN t <= CAST(:teams AS int) THEN :roundA ELSE :roundB END::uuid,
       'Load Team ' || lpad(t::text, 3, '0'), ((t - 1) % CAST(:teams AS int)) + 1, false
FROM generate_series(1, 2 * CAST(:teams AS int)) AS t
SQL, ['teams' => RE_TEAMS, 'roundA' => $rounds[1]['id'], 'roundB' => $rounds[2]['id']]);

    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_participant_round (id, participant_id, round_id, team_id, table_number, result_did_not_start)
SELECT ('018d0098-0000-0000-0002-' || lpad(i::text, 12, '0'))::uuid,
       ('018d0098-0000-0000-0001-' || lpad(i::text, 12, '0'))::uuid,
       CASE WHEN i <= CAST(:solo AS int) + CAST(:teams AS int) * CAST(:size AS int) THEN :roundA ELSE :roundB END::uuid,
       ('018d0098-0000-0000-0007-' || lpad((((i - CAST(:solo AS int) - 1) / CAST(:size AS int)) + 1)::text, 12, '0'))::uuid, NULL, false
FROM generate_series(CAST(:solo AS int) + 1, CAST(:people AS int)) AS i
SQL, ['solo' => RE_SOLO, 'teams' => RE_TEAMS, 'size' => RE_TEAM_SIZE, 'people' => RE_PEOPLE, 'roundA' => $rounds[1]['id'], 'roundB' => $rounds[2]['id']]);

    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_referee (id, competition_id, player_id, added_by_id, added_at)
SELECT ('018d0098-0000-0000-0003-' || lpad(i::text, 12, '0'))::uuid, :competition,
       ('018d0098-0000-0000-0005-' || lpad(i::text, 12, '0'))::uuid, :organiser, now() AT TIME ZONE 'UTC'
FROM generate_series(1, :judges) AS i
SQL, ['competition' => COMPETITION, 'organiser' => ORGANISER, 'judges' => RE_JUDGES]);

    $connection->executeStatement('UPDATE competition SET competitor_pages = true, time_proposals = true WHERE id = ?', [COMPETITION]);
    $connection->executeStatement(
        "UPDATE competition_round SET stopwatch_status = 'finished' WHERE competition_id = ? AND id NOT IN (?) AND stopwatch_status = 'running'",
        [COMPETITION, $roundIds],
        [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING],
    );
});

// --- the displays: per round a live-board screen and a leaderboard overlay, pinned to the round
$competition = $entityManager->find(Competition::class, COMPETITION);
assert($competition instanceof Competition);
$presets = new DisplayPresets(new DisplayDesignSanitizer());
$displays = [];
foreach ($rounds as $index => $round) {
    $roundEntity = $entityManager->find(CompetitionRound::class, $round['id']);
    assert($roundEntity instanceof CompetitionRound);
    foreach ([[DisplayKind::Screen, DisplayPreset::TimerWithLiveBoard], [DisplayKind::Overlay, DisplayPreset::ObsLeaderboardSidebar]] as $k => [$kind, $preset]) {
        $design = $presets->design($preset, $kind, COMPETITION);
        $display = new CompetitionDisplay(
            id: Uuid::fromString(id('0008', $index * 2 + $k + 1)),
            competition: $competition,
            kind: $kind,
            name: $round['name'] . ' ' . $kind->value,
            token: OnlineToken::generate(),
            locale: 'en',
            design: $design,
            createdAt: $now,
            createdBy: null,
            updatedAt: $now,
            usesLiveResults: DisplayDesignSanitizer::usesLiveResults($design),
            usesResults: DisplayDesignSanitizer::usesResults($design),
        );
        $display->follow(DisplayFollowMode::Pinned, $roundEntity, null, $now);
        $entityManager->persist($display);
        $displays[] = ['token' => $display->token, 'round' => $index];
    }
}
$entityManager->flush();

// --- the credentials
$hasher = new SignatureHasher(PropertyAccess::createPropertyAccessor(), ['email', 'password'], $secret);
$heartbeatToken = new CompetitorHeartbeatToken($secret);
$expires = time() + LIFETIME;

$out = ['competitionId' => COMPETITION, 'rounds' => [], 'judges' => [], 'competitors' => [], 'displays' => $displays];
$puzzlePosition = 0;
foreach ($rounds as $index => $round) {
    $solo = $round['category'] === 'solo';
    $entries = [];
    for ($table = 1; $table <= ($solo ? RE_SOLO : RE_TEAMS); $table++) {
        $entries[] = $solo ? 'participant_round:' . id('0002', $table) : 'team:' . id('0007', ($index - 1) * RE_TEAMS + $table);
    }
    $roundPuzzles = [];
    foreach ($round['puzzles'] as $_) {
        $roundPuzzles[] = id('0006', ++$puzzlePosition);
    }
    $out['rounds'][] = [
        'id' => $round['id'],
        'name' => $round['name'],
        'format' => $round['format'],
        'puzzles' => $roundPuzzles,
        'entries' => $entries,
        'judged' => $solo ? RE_SOLO_JUDGED : RE_TEAMS_JUDGED,
    ];
}

$accounts = [];
foreach ($entityManager->getRepository(UserAccount::class)->createQueryBuilder('a')->where("a.userId LIKE 'load|%'")->getQuery()->getResult() as $account) {
    assert($account instanceof UserAccount);
    $accounts[$account->userId] = $account;
}

foreach ($people as $playerId => $person) {
    $account = $accounts[$person['userId']] ?? null;
    assert($account instanceof UserAccount);
    $cookie = (new RememberMeDetails($account->getUserIdentifier(), $expires, $hasher->computeSignatureHash($account, $expires)))->toString();

    if ($person['judge']) {
        $out['judges'][] = ['playerId' => $playerId, 'cookie' => $cookie, 'round' => ($person['n'] - 1) % 3];

        continue;
    }

    $n = $person['n'];
    if ($n <= RE_SOLO) {
        $roundIndex = 0;
        $table = $n;
        $proposer = $table > RE_SOLO_JUDGED;
    } else {
        $team = intdiv($n - RE_SOLO - 1, RE_TEAM_SIZE) + 1; // 1..300
        $roundIndex = $team <= RE_TEAMS ? 1 : 2;
        $table = ($team - 1) % RE_TEAMS + 1;
        // One member (the first) of each team the judges leave alone proposes
        $proposer = $table > RE_TEAMS_JUDGED && ($n - RE_SOLO - 1) % RE_TEAM_SIZE === 0;
    }
    $entry = $out['rounds'][$roundIndex]['entries'][$table - 1];
    $out['competitors'][] = [
        'playerId' => $playerId,
        'participantId' => id('0001', $n),
        'round' => $roundIndex,
        'entry' => $entry,
        'table' => $table,
        'proposer' => $proposer,
        'cookie' => $cookie,
        'heartbeat' => $heartbeatToken->issue($rounds[$roundIndex]['id'], $entry, $playerId, $now),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
