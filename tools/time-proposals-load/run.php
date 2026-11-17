<?php

declare(strict_types=1);

/**
 * Load measurement of competitors' time proposals (competition toolkit P1, R-review-and-plan.md §2.4 item 6): N
 * proposals at the same moment, each a ProposeRoundTime through the real message bus - the event's participants lock
 * (Postgres advisory lock, LockUntilCommittedMiddleware), the transaction and the handler's statements on a real
 * Postgres. HTTP, the firewall and the rate limiter are not part of it (a few ms per request on top, not serialised).
 *
 * Run it against a THROWAWAY copy of the test database (it writes participants, entries and proposals), from the
 * worktree root through the delivery run.sh, e.g.:
 *
 *     psql: CREATE DATABASE speedpuzzling_ctp1_load_scratch TEMPLATE speedpuzzling_ctp1_test
 *     run.sh ctp1 'APP_ENV=dev APP_DEBUG=0 \
 *         DATABASE_URL="postgresql://postgres:postgres@postgres:5432/speedpuzzling_ctp1_load_scratch?serverVersion=18&charset=utf8" \
 *         LOCK_DSN="postgresql+advisory://postgres:postgres@postgres:5432/speedpuzzling_ctp1_load_scratch?serverVersion=18&charset=utf8" \
 *         php tools/time-proposals-load/run.php 100'
 *     psql: DROP DATABASE speedpuzzling_ctp1_load_scratch
 *
 * What it does: turns time proposals on for the fixtures' Results Cup, starts its Final 10 minutes ago, adds N solo
 * entrants linked (a day ago) to a fixture player, then forks N processes from one booted kernel (no connection is
 * open yet - each child opens its own), lets them wait for one common start moment and dispatch one proposal each.
 * Prints every latency's percentiles (dispatch → answer, i.e. waiting for the lock + the handler + the commit).
 *
 * Every child holds two connections (the ORM's and the lock store's): a local Postgres with max_connections = 100 takes
 * about 40 children at once - more fail to connect. `php run.php 1` repeated gives the uncontended service time.
 */

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\SymfonyApplicationKernel;
use SpeedPuzzling\Web\Message\ProposeRoundTime;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const COMPETITION = '018d0020-0000-0000-0000-000000000001'; // OfficialResultsFixture::COMPETITION_RESULTS_CUP
const ROUND = '018d0020-0000-0000-0000-000000000103'; // OfficialResultsFixture::ROUND_FINAL
const PLAYER = '018d0000-0000-0000-0000-000000000001'; // PlayerFixture::PLAYER_ADMIN

$count = max(1, (int) ($argv[1] ?? 100));

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

if (!str_contains((string) ($_SERVER['DATABASE_URL'] ?? ''), '_scratch')) {
    fwrite(STDERR, "Refusing to run: DATABASE_URL must name a *_scratch copy of the test database.\n");
    exit(2);
}

$kernel = new SymfonyApplicationKernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

// --- set up in the parent, then close every connection before forking
$entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
assert($entityManager instanceof \Doctrine\ORM\EntityManagerInterface);
$connection = $entityManager->getConnection();
$now = new DateTimeImmutable();

$connection->executeStatement('UPDATE competition SET competitor_pages = true, time_proposals = true WHERE id = ?', [COMPETITION]);
$connection->executeStatement(
    "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = ?, stopwatch_stopped_at = NULL WHERE id = ?",
    [$now->modify('-10 minutes')->format('Y-m-d H:i:s'), ROUND],
);

$round = $entityManager->find(CompetitionRound::class, ROUND);
$player = $entityManager->find(Player::class, PLAYER);
assert($round instanceof CompetitionRound && $player instanceof Player);

$entries = [];
for ($i = 0; $i < $count; $i++) {
    $participant = new CompetitionParticipant(Uuid::uuid7(), 'Load ' . $i, 'cz', $round->competition, ParticipantSource::Manual);
    $participant->connect($player, $now->modify('-1 day'));
    $entityManager->persist($participant);
    $entry = new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round);
    $entityManager->persist($entry);
    $entries[] = $entry->entryRef()->toString();
}

$entityManager->flush();
$entityManager->clear();
$connection->close();

// --- fork: every child waits for the same moment, dispatches one proposal, writes its latency
$startAt = microtime(true) + 3.0 + $count * 0.01;
$outDir = sys_get_temp_dir() . '/time-proposals-load-' . getmypid();
mkdir($outDir);
$children = [];

foreach ($entries as $index => $entryRef) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");
        exit(1);
    }

    if ($pid === 0) {
        $bus = $kernel->getContainer()->get('messenger.default_bus');
        assert($bus instanceof MessageBusInterface);
        // Warm the child like a FrankenPHP worker that served requests before: its connections open, the bus, the
        // handler and the entity metadata loaded - one proposal refused at once (an id that is no UUIDv7), which takes
        // the lock and a transaction too. Staggered, so the warm-ups do not queue behind each other at the start
        usleep($index * 10_000);
        $bus->dispatch(new ProposeRoundTime(COMPETITION, ROUND, $entryRef, Uuid::uuid4()->toString(), PLAYER, 0, (int) floor(microtime(true) * 1000)));
        $kernel->getContainer()->get('doctrine.orm.entity_manager')->clear();

        time_sleep_until($startAt);
        $tappedAtMs = (int) floor(microtime(true) * 1000) - 1500;
        $started = hrtime(true);

        $answer = $bus->dispatch(new ProposeRoundTime(
            competitionId: COMPETITION,
            roundId: ROUND,
            entry: $entryRef,
            proposalId: Uuid::uuid7()->toString(),
            actingPlayerId: PLAYER,
            tappedAtMs: $tappedAtMs,
            receivedAtMs: (int) floor(microtime(true) * 1000),
        ))->last(HandledStamp::class)?->getResult();

        $ms = (hrtime(true) - $started) / 1e6;
        $status = $answer instanceof TimeProposalAnswer ? $answer->status . ($answer->reason !== null ? ':' . $answer->reason : '') : 'none';
        file_put_contents($outDir . '/' . $index, sprintf("%.3f %s\n", $ms, $status));
        exit(0);
    }

    $children[] = $pid;
}

foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
}

$latencies = [];
$statuses = [];
foreach (glob($outDir . '/*') ?: [] as $file) {
    [$ms, $status] = explode(' ', trim((string) file_get_contents($file)), 2);
    $latencies[] = (float) $ms;
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    unlink($file);
}
rmdir($outDir);
sort($latencies);

$percentile = static function (float $p) use ($latencies): float {
    if ($latencies === []) {
        return 0.0;
    }

    return $latencies[min(count($latencies) - 1, (int) ceil($p * count($latencies)) - 1)];
};

printf("%d concurrent proposals - answers: %s\n", $count, json_encode($statuses));
printf("latency ms: min %.1f  p50 %.1f  p95 %.1f  p99 %.1f  max %.1f\n", $latencies[0] ?? 0.0, $percentile(0.50), $percentile(0.95), $percentile(0.99), $latencies === [] ? 0.0 : end($latencies));
