<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerElo;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\PlayerRatingEntry;
use SpeedPuzzling\Web\Results\RatingMove;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ladder's 7-day movement (docs/features/msp-rating-history.md): every row against the same list ranked on the
 * stored day a week ago - its filters included -, "New" for a row not on that list, no points across a formula change.
 */
final class GetPlayerRatingRankingMovementTest extends KernelTestCase
{
    private const int PIECES_COUNT = 500;

    private GetPlayerRatingRanking $query;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var GetPlayerRatingRanking $query */
        $query = $container->get(GetPlayerRatingRanking::class);
        $this->query = $query;

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;

        /** @var PlayerRepository $players */
        $players = $container->get(PlayerRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Today: Admin (cz) 1500, Regular (cz) and With favorites (de) 1400, With stripe (gb) 1200
        foreach (
            [
            PlayerFixture::PLAYER_ADMIN => 1.5,
            PlayerFixture::PLAYER_REGULAR => 1.4,
            PlayerFixture::PLAYER_WITH_FAVORITES => 1.4,
            PlayerFixture::PLAYER_WITH_STRIPE => 1.2,
            ] as $playerId => $rating
        ) {
            $em->persist(new PlayerElo(id: Uuid::uuid7(), player: $players->get($playerId), piecesCount: self::PIECES_COUNT, eloRating: $rating));
        }

        $em->flush();
    }

    public function testEveryRowAgainstTheListAWeekAgo(): void
    {
        // A week before 15 Jun: Regular 1450 (#1), Admin 1300 (#2), With stripe 1200 (#3), With favorites not rated
        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_REGULAR => 1.45, PlayerFixture::PLAYER_ADMIN => 1.3, PlayerFixture::PLAYER_WITH_STRIPE => 1.2, PlayerFixture::PLAYER_WITH_FAVORITES => null]);
        // A later day is never the compared one
        $this->snapshot('2026-06-12', [PlayerFixture::PLAYER_REGULAR => 1.0, PlayerFixture::PLAYER_ADMIN => 1.0]);

        $moves = $this->moves($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-06-15')));

        self::assertSame([1, 200], [$moves[PlayerFixture::PLAYER_ADMIN]->placesGained, $moves[PlayerFixture::PLAYER_ADMIN]->pointsChange]);
        self::assertSame([-1, -50], [$moves[PlayerFixture::PLAYER_REGULAR]->placesGained, $moves[PlayerFixture::PLAYER_REGULAR]->pointsChange]);
        self::assertSame([-1, 0], [$moves[PlayerFixture::PLAYER_WITH_STRIPE]->placesGained, $moves[PlayerFixture::PLAYER_WITH_STRIPE]->pointsChange]);
        self::assertTrue($moves[PlayerFixture::PLAYER_WITH_FAVORITES]->isNew);
        self::assertSame('2026-06-08', $moves[PlayerFixture::PLAYER_ADMIN]->since->format('Y-m-d'));
    }

    public function testTheMovementFollowsTheFilters(): void
    {
        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_REGULAR => 1.45, PlayerFixture::PLAYER_ADMIN => 1.3, PlayerFixture::PLAYER_WITH_STRIPE => 1.6]);

        // In Czechia the gb player above both never counts: Regular #1 -> #2, Admin #2 -> #1
        $moves = $this->moves($this->query->window(self::PIECES_COUNT, 100, country: 'cz', now: self::now('2026-06-15')));

        self::assertSame(1, $moves[PlayerFixture::PLAYER_ADMIN]->placesGained);
        self::assertSame(-1, $moves[PlayerFixture::PLAYER_REGULAR]->placesGained);
    }

    public function testTheFavouritesListRanksItsOwnStoredDay(): void
    {
        // Regular's favourites: With stripe; the list is Regular + With stripe
        $this->connection->executeStatement('UPDATE player SET favorite_players = :favorites WHERE id = :id', ['favorites' => json_encode([PlayerFixture::PLAYER_WITH_STRIPE]), 'id' => PlayerFixture::PLAYER_REGULAR]);
        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_ADMIN => 1.9, PlayerFixture::PLAYER_WITH_STRIPE => 1.5, PlayerFixture::PLAYER_REGULAR => 1.3]);

        $moves = $this->moves($this->query->window(self::PIECES_COUNT, 100, favoriteOfPlayerId: PlayerFixture::PLAYER_REGULAR, now: self::now('2026-06-15')));

        // Admin above both never counts: With stripe #1 -> #2, Regular #2 -> #1
        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE], array_keys($moves));
        self::assertSame(1, $moves[PlayerFixture::PLAYER_REGULAR]->placesGained);
        self::assertSame(-1, $moves[PlayerFixture::PLAYER_WITH_STRIPE]->placesGained);
    }

    public function testPlayersOffTheLadderTodayAreNotRankedOnTheStoredDayEither(): void
    {
        // With stripe opted out of rankings since: neither listed today nor counted above anybody a week ago
        $this->connection->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_WITH_STRIPE => 2.0, PlayerFixture::PLAYER_PRIVATE => 1.9, PlayerFixture::PLAYER_ADMIN => 1.5, PlayerFixture::PLAYER_REGULAR => 1.3]);

        $moves = $this->moves($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-06-15')));

        self::assertArrayNotHasKey(PlayerFixture::PLAYER_WITH_STRIPE, $moves);
        self::assertSame(0, $moves[PlayerFixture::PLAYER_ADMIN]->placesGained);
        // Regular #2 a week ago, #2 today (tied with With favorites, who is new)
        self::assertSame(0, $moves[PlayerFixture::PLAYER_REGULAR]->placesGained);
    }

    public function testEqualStoredRatingsShareThePlace(): void
    {
        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_REGULAR => 1.6, PlayerFixture::PLAYER_WITH_FAVORITES => 1.6, PlayerFixture::PLAYER_ADMIN => 1.5]);

        $moves = $this->moves($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-06-15')));

        // Both #1 a week ago, both #2 today; Admin #3 -> #1
        self::assertSame(-1, $moves[PlayerFixture::PLAYER_REGULAR]->placesGained);
        self::assertSame(-1, $moves[PlayerFixture::PLAYER_WITH_FAVORITES]->placesGained);
        self::assertSame(2, $moves[PlayerFixture::PLAYER_ADMIN]->placesGained);
    }

    public function testThePlayersTheViewerHidesCloseUpOnTheStoredDayToo(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_WITH_STRIPE => 2.0, PlayerFixture::PLAYER_ADMIN => 1.5, PlayerFixture::PLAYER_REGULAR => 1.3]);

        /** @var GetPlayerRatingRanking $query */
        $query = self::getContainer()->get(GetPlayerRatingRanking::class);
        $moves = $this->moves($query->window(self::PIECES_COUNT, 100, now: self::now('2026-06-15')));

        self::assertArrayNotHasKey(PlayerFixture::PLAYER_WITH_STRIPE, $moves);
        // Without the hidden player above: Admin #1 -> #1, Regular #2 -> #2
        self::assertSame(0, $moves[PlayerFixture::PLAYER_ADMIN]->placesGained);
        self::assertSame(0, $moves[PlayerFixture::PLAYER_REGULAR]->placesGained);
    }

    public function testAMissingDayFallsBackToTheLastStoredOneBeforeIt(): void
    {
        $this->snapshot('2026-06-06', [PlayerFixture::PLAYER_REGULAR => 1.45, PlayerFixture::PLAYER_ADMIN => 1.3]);

        $moves = $this->moves($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-06-15')));

        self::assertSame('2026-06-06', $moves[PlayerFixture::PLAYER_ADMIN]->since->format('Y-m-d'));
        self::assertSame(1, $moves[PlayerFixture::PLAYER_ADMIN]->placesGained);
    }

    public function testAStoredDayBeforeTheFormulaChangeOrLongAgoIsNeverCompared(): void
    {
        // The batch stopped storing days: the last one before 18 Oct is the old formula's - no movement at all
        $this->snapshot('2026-10-10', [PlayerFixture::PLAYER_REGULAR => 1.45, PlayerFixture::PLAYER_ADMIN => 1.3]);

        foreach ($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-10-20'))->rows as $entry) {
            self::assertNull($entry->move);
        }

        // More than a week older than the reference day says nothing about this week either
        $this->snapshot('2026-05-30', [PlayerFixture::PLAYER_REGULAR => 1.45, PlayerFixture::PLAYER_ADMIN => 1.3]);

        foreach ($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-06-15'))->rows as $entry) {
            self::assertNull($entry->move);
        }
    }

    public function testNoPointsAcrossAFormulaChangeAndNeverReachingBeforeIt(): void
    {
        // 13 Oct would be a week before 20 Oct - the formula changed on 18 Oct, so 18 Oct is compared and points stay
        $this->snapshot('2026-10-13', [PlayerFixture::PLAYER_ADMIN => 0.5]);
        $this->snapshot('2026-10-18', [PlayerFixture::PLAYER_REGULAR => 1.45, PlayerFixture::PLAYER_ADMIN => 1.3]);

        $moves = $this->moves($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-10-20')));

        self::assertSame('2026-10-18', $moves[PlayerFixture::PLAYER_ADMIN]->since->format('Y-m-d'));
        self::assertSame(1, $moves[PlayerFixture::PLAYER_ADMIN]->placesGained);
        self::assertSame(200, $moves[PlayerFixture::PLAYER_ADMIN]->pointsChange);
    }

    public function testWithoutAStoredDayOrWithoutNowThereIsNoMovement(): void
    {
        foreach ($this->query->window(self::PIECES_COUNT, 100, now: self::now('2026-06-15'))->rows as $entry) {
            self::assertNull($entry->move);
        }

        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_ADMIN => 1.3]);

        foreach ($this->query->window(self::PIECES_COUNT, 100)->rows as $entry) {
            self::assertNull($entry->move);
        }
    }

    public function testThePlaceOnTheComparedDayForTheHeaderChip(): void
    {
        $this->snapshot('2026-06-08', [PlayerFixture::PLAYER_REGULAR => 1.45, PlayerFixture::PLAYER_ADMIN => 1.3]);

        $place = $this->query->sqlPlaceOnStoredDayOf(':playerId', '500', ':day', ':notBefore');
        /** @var int|string $adminPlace */
        $adminPlace = $this->connection->fetchOne("SELECT {$place}", ['playerId' => PlayerFixture::PLAYER_ADMIN, 'day' => '2026-06-08', 'notBefore' => '2026-06-01']);

        self::assertSame(2, (int) $adminPlace);
        self::assertNull($this->connection->fetchOne("SELECT {$place}", ['playerId' => PlayerFixture::PLAYER_WITH_FAVORITES, 'day' => '2026-06-08', 'notBefore' => '2026-06-01']));
    }

    /**
     * @param array<string, null|float> $ratings
     */
    private function snapshot(string $day, array $ratings): void
    {
        foreach ($ratings as $playerId => $rating) {
            $this->connection->executeStatement(
                'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, computed_at) VALUES (gen_random_uuid(), :player, :pieces, :day, :rating, NOW())',
                ['player' => $playerId, 'pieces' => self::PIECES_COUNT, 'day' => $day, 'rating' => $rating],
            );
        }
    }

    /**
     * @param \SpeedPuzzling\Web\Results\LeaderboardSlice<PlayerRatingEntry> $slice
     * @return array<string, RatingMove>
     */
    private function moves(\SpeedPuzzling\Web\Results\LeaderboardSlice $slice): array
    {
        $moves = [];

        foreach ($slice->rows as $entry) {
            self::assertNotNull($entry->move);
            $moves[$entry->playerId] = $entry->move;
        }

        return $moves;
    }

    private static function now(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day . ' 12:00', new DateTimeZone('UTC'));
    }
}
