<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Standings;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetPublicStandingHead;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\PublicStandingRow;
use SpeedPuzzling\Web\Results\PublicStandingSnapshot;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\PublishedStanding;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The guest snapshot of a standing (competition toolkit v2 S1, displays-standings.md §2.1): viewer-independent whoever
 * is signed in (the blocker still gets the blocked row, the allowed friend still gets the private player masked - both
 * services read the signed-in user, so publicView(asGuest: true) must switch them off explicitly), cached per
 * generation (a bump = fresh), and computed without the cache when the cache fails.
 *
 * Results Cup: Group A published (Anna, Ben, Cara, Dan), Group B published here - Gina Quick is the private
 * PLAYER_PRIVATE ("Jane Smith"), whose friend (allow list) is PLAYER_WITH_FAVORITES.
 */
final class PublicStandingSnapshotsTest extends KernelTestCase
{
    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string FRIEND = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string BLOCKER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string BLOCKED = PlayerFixture::PLAYER_WITH_STRIPE;

    private Connection $database;
    private string $standingId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]);
        $this->standingId = PublishedStanding::insert($this->database, self::CUP, 'overall-solo', 'Overall solo', 'solo', [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B]);
    }

    protected function tearDown(): void
    {
        TestingViewer::signOut(self::getContainer());
        parent::tearDown();
    }

    public function testTheAllowedFriendSignedInDoesNotUnmaskThePrivatePlayer(): void
    {
        TestingViewer::signIn(self::getContainer(), self::FRIEND);
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($this->standingId);

        // No canary without it: the viewer's own view names her profile
        $viewers = self::getContainer()->get(GetStandingResults::class)->publicView($standing, self::FRIEND);
        self::assertNotNull(self::codeOf($viewers->rows, 'Gina Quick'), 'No canary: the friend does not see her profile.');

        $snapshot = $this->snapshots()->forGuest($this->standingId);
        self::assertNotNull($snapshot);
        $gina = self::row($snapshot, 'Gina Quick');
        self::assertSame([['name' => 'Gina Quick', 'country' => 'us', 'playerCode' => null]], $gina['entrants']);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) json_encode($snapshot->toArray()));
        self::assertStringNotContainsString('Jane Smith', (string) json_encode($snapshot->toArray()));
    }

    public function testTheBlockerSignedInStillGetsTheBlockedRow(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => self::BLOCKED, 'id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BLOCKER, 'blocked' => self::BLOCKED],
        );
        TestingViewer::signIn(self::getContainer(), self::BLOCKER);
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($this->standingId);

        $viewers = self::getContainer()->get(GetStandingResults::class)->publicView($standing, self::BLOCKER);
        self::assertFalse(array_any($viewers->rows, static fn (PublicStandingRow $row): bool => $row->row->name === 'Ben Steady'), 'No canary: the blocker sees the blocked row.');

        $snapshot = $this->snapshots()->forGuest($this->standingId);
        self::assertNotNull($snapshot);
        // Every entrant of the two groups is in one of them only - incomplete, listed without a rank
        self::assertNull(self::row($snapshot, 'Ben Steady')['rank']);
        // His public profile: the #code a guest sees on the page
        self::assertNotNull(self::row($snapshot, 'Ben Steady')['entrants'][0]['playerCode']);
    }

    public function testANewGenerationIsComputedAfresh(): void
    {
        $snapshots = $this->snapshots();
        $first = $snapshots->forGuest($this->standingId);
        self::assertNotNull($first);
        self::assertTrue($first->isPublished());
        self::assertSame(strtolower(self::CUP), $first->competitionId());

        $this->database->executeStatement("UPDATE competition_participant SET name = 'Anna Renamed' WHERE id = :id", ['id' => OfficialResultsFixture::PARTICIPANT_ANNA]);
        self::row($snapshots->forGuest($this->standingId) ?? self::fail(), 'Anna Fast');

        $generation = $snapshots->generation(self::CUP);
        $snapshots->changed(self::CUP);
        self::assertNotSame($generation, $snapshots->generation(self::CUP));
        self::row($snapshots->forGuest($this->standingId) ?? self::fail(), 'Anna Renamed');

        // Unpublished: a snapshot without rows; unknown: none
        $this->database->executeStatement('UPDATE competition_standing SET published_at = NULL WHERE id = :id', ['id' => $this->standingId]);
        $snapshots->changed(self::CUP);
        $unpublished = $snapshots->forGuest($this->standingId);
        self::assertNotNull($unpublished);
        self::assertFalse($unpublished->isPublished());
        self::assertSame([], $unpublished->toArray()['rows']);
        self::assertNull($snapshots->forGuest(Uuid::uuid7()->toString()));
        self::assertNull($snapshots->forGuest('not-a-uuid'));

        self::assertContains($this->standingId, array_column($snapshots->listFor(self::CUP), 'id'));
    }

    public function testACacheFailureIsComputedWithoutIt(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $failing = new class () implements CacheInterface {
            /**
             * @param array<mixed> $metadata
             */
            public function get(string $key, callable $callback, null|float $beta = null, null|array &$metadata = null): mixed
            {
                throw new \RuntimeException('Redis is down');
            }

            public function delete(string $key): bool
            {
                throw new \RuntimeException('Redis is down');
            }
        };
        $container = self::getContainer();
        $snapshots = new PublicStandingSnapshots(
            $container->get(GetPublicStandingHead::class),
            $container->get(GetStandingResults::class),
            $container->get(GetCompetitionStandings::class),
            $failing,
            $container->get(ClockInterface::class),
            $logger,
        );

        $snapshot = $snapshots->forGuest($this->standingId);
        self::assertNotNull($snapshot);
        self::assertSame('Anna Fast', self::row($snapshot, 'Anna Fast')['name']);
        $snapshots->changed(self::CUP);
        self::assertNotSame([], $logger->warnings);
    }

    private function snapshots(): PublicStandingSnapshots
    {
        return self::getContainer()->get(PublicStandingSnapshots::class);
    }

    /**
     * @return array{name: string, rank: null|int, entrants: list<array{name: string, country: null|string, playerCode: null|string}>, ...}
     */
    private static function row(PublicStandingSnapshot $snapshot, string $name): array
    {
        foreach ($snapshot->toArray()['rows'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        self::fail('No row ' . $name);
    }

    /**
     * @param list<PublicStandingRow> $rows
     */
    private static function codeOf(array $rows, string $name): null|string
    {
        foreach ($rows as $row) {
            if ($row->row->name === $name) {
                return $row->row->entrants[0]->playerCode;
            }
        }

        return null;
    }
}
