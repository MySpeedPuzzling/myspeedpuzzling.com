<?php

declare(strict_types=1);

/**
 * The event of the competition load test (tools/competition-load/README.md "Data") - run by `load.sh setup` on the
 * THROWAWAY copy only, with the stack's environment (APP_ENV=prod). Idempotent: every run resets the round.
 *
 * In the fixtures' Results Cup: round "Load Round" - solo, `pick` (2 of 3 puzzles; `setup.php total`: one puzzle, `total`), 300 made-up entrants
 * "Load Tester 001..300" at tables 1..300, each linked (a day ago) to an account of their own (players
 * 018d0098-…-000000000NNN), the stopwatch running since 10 minutes ago, a 600-minute limit; 6 referees "Load Judge 1..6";
 * competitor pages + time proposals on; no other round of the event runs.
 *
 * Prints the credentials as JSON (stdout): per judge and competitor the remember-me cookie the site itself would mint
 * (Symfony's SignatureHasher over the same signature properties and kernel.secret - config/packages/security.php), per
 * competitor the heartbeat token the state JSON would hand out (CompetitorHeartbeatToken). They are access rights to
 * a throwaway copy only - load.sh writes them into out/ (gitignored).
 */

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitorHeartbeatToken;
use SpeedPuzzling\Web\SymfonyApplicationKernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const COMPETITION = '018d0020-0000-0000-0000-000000000001'; // OfficialResultsFixture::COMPETITION_RESULTS_CUP
const ORGANISER = '018d0000-0000-0000-0000-000000000005'; // PlayerFixture::PLAYER_WITH_STRIPE
const ROUND = '018d0098-0000-0000-0000-000000000001';
const PUZZLES = [
    '018d0003-0000-0000-0000-000000000012', // PUZZLE_1500_01
    '018d0003-0000-0000-0000-000000000013', // PUZZLE_1500_02
    '018d0003-0000-0000-0000-000000000015', // PUZZLE_3000
];
const ENTRANTS = 300;
const JUDGES = 6;
const LIFETIME = 30 * 86400;

function id(string $kind, int $n): string
{
    return '018d0098-0000-0000-' . $kind . '-' . str_pad((string) $n, 12, '0', STR_PAD_LEFT);
}

$format = ($argv[1] ?? 'pick') === 'total' ? 'total' : 'pick';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$databaseName = (string) preg_replace('/\?.*$/', '', basename((string) ($_SERVER['DATABASE_URL'] ?? '')));

if (preg_match('/_test_load$/', $databaseName) !== 1) {
    fwrite(STDERR, "Refusing to run: the database must be the load copy (…_test_load), not '$databaseName'.\n");
    exit(2);
}

$kernel = new SymfonyApplicationKernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();
$doctrine = $container->get('doctrine');
assert($doctrine instanceof \Doctrine\Persistence\ManagerRegistry);
$entityManager = $doctrine->getManager();
assert($entityManager instanceof EntityManagerInterface);
$connection = $entityManager->getConnection();
assert($connection instanceof Connection);
$secret = (string) $container->getParameter('kernel.secret');
$now = new DateTimeImmutable();

// --- the accounts (created once, kept between runs)
$people = [];
for ($i = 1; $i <= ENTRANTS + JUDGES; $i++) {
    $judge = $i > ENTRANTS;
    $playerId = id($judge ? '0005' : '0004', $judge ? $i - ENTRANTS : $i);
    $userId = 'load|' . $playerId;
    $people[$playerId] = ['userId' => $userId, 'judge' => $judge];

    if ($entityManager->find(Player::class, $playerId) !== null) {
        continue;
    }

    $account = new UserAccount(Uuid::uuid7(), $userId, 'load-' . substr($playerId, -6) . ($judge ? '-judge' : '') . '@example.com', $now);
    $account->markEmailVerified($now);
    $entityManager->persist($account);
    $name = $judge ? 'Load Judge ' . ($i - ENTRANTS) : 'Load Tester ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $entityManager->persist(new Player(Uuid::fromString($playerId), 'load' . ($judge ? 'j' : 'c') . substr($playerId, -4), $userId, $name, $now->modify('-30 days')));
}
$entityManager->flush();
$entityManager->clear();

// --- the round, its entrants and referees, anew
$connection->transactional(static function (Connection $connection) use ($format): void {
    $round = ROUND;
    $connection->executeStatement('DELETE FROM competition_time_proposal WHERE round_id = ?', [$round]);
    $connection->executeStatement('DELETE FROM round_result_change_receipt WHERE round_id = ?', [$round]);
    $connection->executeStatement('DELETE FROM competition_participant_round WHERE round_id = ?', [$round]);
    $connection->executeStatement('DELETE FROM competition_round_puzzle WHERE round_id = ?', [$round]);
    $connection->executeStatement('DELETE FROM competition_round WHERE id = ?', [$round]);
    $connection->executeStatement("DELETE FROM competition_participant WHERE id::text LIKE '018d0098-0000-0000-0001-%'");
    $connection->executeStatement("DELETE FROM competition_referee WHERE id::text LIKE '018d0098-0000-0000-0003-%'");

    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_round (id, competition_id, name, slug, category, puzzle_format, puzzles_to_solve, minutes_limit, starts_at,
                               timezone, stopwatch_status, stopwatch_started_at, stopwatch_first_started_at,
                               table_numbers_off, reveal_delay_minutes, start_time_known)
VALUES (:round, :competition, 'Load Round', 'load-round', 'solo', :format, :toSolve, 600,
        (now() AT TIME ZONE 'UTC') - interval '10 minutes', 'Europe/Prague',
        'running', (now() AT TIME ZONE 'UTC') - interval '10 minutes', (now() AT TIME ZONE 'UTC') - interval '10 minutes',
        false, 10, true)
SQL, ['round' => $round, 'competition' => COMPETITION, 'format' => $format, 'toSolve' => $format === 'pick' ? 2 : null]);

    foreach ($format === 'pick' ? PUZZLES : [PUZZLES[0]] as $position => $puzzleId) {
        $connection->executeStatement(
            "INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, position, hide_until_round_starts, reveal_mode, hides_everywhere)
             VALUES (?, ?, ?, ?, false, 'automatic', false)",
            [id('0006', $position + 1), $round, $puzzleId, $position + 1],
        );
    }

    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_participant (id, competition_id, name, country, source, player_id, connected_at)
SELECT ('018d0098-0000-0000-0001-' || lpad(i::text, 12, '0'))::uuid, :competition,
       'Load Tester ' || lpad(i::text, 3, '0'), (ARRAY['cz', 'de', 'sk', 'us', 'pl'])[1 + i % 5], 'manual',
       ('018d0098-0000-0000-0004-' || lpad(i::text, 12, '0'))::uuid, (now() AT TIME ZONE 'UTC') - interval '1 day'
FROM generate_series(1, :entrants) AS i
SQL, ['competition' => COMPETITION, 'entrants' => ENTRANTS]);

    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_participant_round (id, participant_id, round_id, table_number, result_did_not_start)
SELECT ('018d0098-0000-0000-0002-' || lpad(i::text, 12, '0'))::uuid,
       ('018d0098-0000-0000-0001-' || lpad(i::text, 12, '0'))::uuid, :round, i, false
FROM generate_series(1, :entrants) AS i
SQL, ['round' => $round, 'entrants' => ENTRANTS]);

    $connection->executeStatement(<<<'SQL'
INSERT INTO competition_referee (id, competition_id, player_id, added_by_id, added_at)
SELECT ('018d0098-0000-0000-0003-' || lpad(i::text, 12, '0'))::uuid, :competition,
       ('018d0098-0000-0000-0005-' || lpad(i::text, 12, '0'))::uuid, :organiser, now() AT TIME ZONE 'UTC'
FROM generate_series(1, :judges) AS i
SQL, ['competition' => COMPETITION, 'organiser' => ORGANISER, 'judges' => JUDGES]);

    $connection->executeStatement('UPDATE competition SET competitor_pages = true, time_proposals = true WHERE id = ?', [COMPETITION]);
    $connection->executeStatement(
        "UPDATE competition_round SET stopwatch_status = 'finished' WHERE competition_id = ? AND id <> ? AND stopwatch_status = 'running'",
        [COMPETITION, $round],
    );
});

// --- the credentials: the cookie the site mints on a sign-in, the heartbeat token the state JSON hands out
$hasher = new SignatureHasher(PropertyAccess::createPropertyAccessor(), ['email', 'password'], $secret);
$heartbeatToken = new CompetitorHeartbeatToken($secret);
$expires = time() + LIFETIME;
$out = ['competitionId' => COMPETITION, 'roundId' => ROUND, 'roundPuzzles' => [id('0006', 1), id('0006', 2), id('0006', 3)], 'judges' => [], 'competitors' => []];

foreach ($people as $playerId => $person) {
    $account = $entityManager->getRepository(UserAccount::class)->findOneBy(['userId' => $person['userId']]);
    assert($account instanceof UserAccount);
    $cookie = (new RememberMeDetails($account->getUserIdentifier(), $expires, $hasher->computeSignatureHash($account, $expires)))->toString();

    if ($person['judge']) {
        $out['judges'][] = ['playerId' => $playerId, 'cookie' => $cookie];

        continue;
    }

    $n = (int) substr($playerId, -12);
    $entry = 'participant_round:' . id('0002', $n);
    $out['competitors'][] = [
        'playerId' => $playerId,
        'participantId' => id('0001', $n),
        'entry' => $entry,
        'table' => $n,
        'cookie' => $cookie,
        'heartbeat' => $heartbeatToken->issue(ROUND, $entry, $playerId, $now),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
