<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetPlayerCard;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The player card's numbers (docs/features/players-page/README.md, stream S1). Fixtures: PLAYER_REGULAR joined an
 * event (CompetitionParticipantFixture), PLAYER_WITH_STRIPE has sell/swap items, UserBlockFixture: PLAYER_REGULAR
 * blocks PLAYER_PRIVATE.
 */
final class GetPlayerCardTest extends KernelTestCase
{
    private GetPlayerCard $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayerCard::class);
        $this->database = self::getContainer()->get(Connection::class);
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
    }

    public function testTheNumbersAreThePrecomputedRow(): void
    {
        $card = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR, withActivity: true);

        $row = $this->database->fetchAssociative(
            'SELECT solved_total, pieces_total, favorites_count, best500_seconds, best1000_seconds, last_solved_at FROM community_player_stats WHERE player_id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        self::assertIsArray($row);
        /** @var array{solved_total: int|string, pieces_total: int|string, favorites_count: int|string, best500_seconds: null|int|string, best1000_seconds: null|int|string, last_solved_at: null|string} $row */
        self::assertGreaterThan(0, $card->solvedTotal);
        self::assertSame((int) $row['solved_total'], $card->solvedTotal);
        self::assertSame((int) $row['pieces_total'], $card->piecesTotal);
        self::assertSame((int) $row['favorites_count'], $card->favoritesCount);
        self::assertSame($row['best500_seconds'] === null ? null : (int) $row['best500_seconds'], $card->best500Seconds);
        self::assertSame($row['best1000_seconds'] === null ? null : (int) $row['best1000_seconds'], $card->best1000Seconds);
        self::assertIsString($row['last_solved_at']);
        self::assertEquals(new DateTimeImmutable($row['last_solved_at']), $card->lastSolvedAt);

        self::assertNotNull($card->monthlySolves);
        self::assertCount(12, $card->monthlySolves);
        self::assertGreaterThanOrEqual(1, $card->busiestMonth());
    }

    public function testTheActivityStripIsLoadedOnlyWhenAsked(): void
    {
        self::assertNull($this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR, withActivity: false)->monthlySolves);
    }

    public function testTheStripsMonthsEndWithTheCurrentOne(): void
    {
        $card = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR, withActivity: true);
        $now = new DateTimeImmutable('2026-03-31 23:30:00');

        $bars = $card->activity($now);

        self::assertCount(12, $bars);
        self::assertSame('2025-04', $bars[0]['month']->format('Y-m'));
        self::assertSame('2026-03', $bars[11]['month']->format('Y-m'));
        self::assertSame($card->monthlySolves, array_column($bars, 'solved'));
    }

    public function testFactsComeFromTheSameStatement(): void
    {
        $regular = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR, withActivity: false);
        self::assertTrue($regular->competesInEvents, 'Joined an event');
        self::assertFalse($regular->swapsPuzzles);
        self::assertFalse($regular->onInstagram);

        $stripe = $this->query->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE, withActivity: false);
        self::assertTrue($stripe->swapsPuzzles, 'Has a sell/swap list');

        $this->database->executeStatement(
            "UPDATE player SET instagram = 'sarah.puzzles' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        self::assertTrue($this->query->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE, withActivity: false)->onInstagram);
    }

    public function testRatingAndTierAreOnFiveHundredPiecesAndGoneForARankingOptOut(): void
    {
        $this->rate(PlayerFixture::PLAYER_REGULAR, elo: 0.8734, tier: SkillTier::Expert);

        $card = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR, withActivity: false);
        self::assertSame(873, $card->mspRating);
        self::assertSame(SkillTier::Expert, $card->skillTier);
        self::assertFalse($card->rankingOptedOut);

        $this->database->executeStatement(
            'UPDATE player SET ranking_opted_out = true WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $card = $this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR, withActivity: false);
        self::assertTrue($card->rankingOptedOut);
        self::assertNull($card->mspRating);
        self::assertNull($card->skillTier);
    }

    public function testAPlayerWithoutAStatsRowYetHasZeros(): void
    {
        $this->database->executeStatement(
            'DELETE FROM community_player_stats WHERE player_id = :id',
            ['id' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        $card = $this->query->byPlayerId(PlayerFixture::PLAYER_WITH_FAVORITES, withActivity: true);

        self::assertSame(0, $card->solvedTotal);
        self::assertSame(0, $card->piecesTotal);
        self::assertNull($card->lastSolvedAt);
        self::assertNull($card->monthlySolves);
        self::assertSame(1, $card->busiestMonth());
    }

    public function testABlockedPlayerHasNoCardForTheBlocker(): void
    {
        // Somebody else sees the card
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        $this->query->byPlayerId(PlayerFixture::PLAYER_PRIVATE, withActivity: false);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $this->expectException(PlayerNotFound::class);
        $this->query->byPlayerId(PlayerFixture::PLAYER_PRIVATE, withActivity: false);
    }

    public function testAnUnknownPlayerIsNotFound(): void
    {
        $this->expectException(PlayerNotFound::class);
        $this->query->byPlayerId(Uuid::uuid7()->toString(), withActivity: false);
    }

    private function rate(string $playerId, float $elo, SkillTier $tier): void
    {
        $this->database->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :elo, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'elo' => $elo],
        );
        // A 1000-piece rating must not be the card's
        $this->database->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 1000, 0.1, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId],
        );
        $this->database->executeStatement(
            "INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at)
             VALUES (:id, :player, 500, 0.9, :tier, 90.0, 'high', 12, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'tier' => $tier->value],
        );
    }
}
