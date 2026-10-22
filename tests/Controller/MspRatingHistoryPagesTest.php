<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The MSP Rating history on the pages (docs/features/msp-rating-history.md): the ladder's movement and "This week",
 * the season sneak peek, the header chip's arrow and the profile's lazy rating block.
 */
final class MspRatingHistoryPagesTest extends WebTestCase
{
    public function testTheLadderShowsTheMovementThisWeekAndTheSeason(): void
    {
        $browser = $this->browser('2026-06-21 12:00');
        $this->world();

        $crawler = $browser->request('GET', '/en/msp-rating');

        self::assertResponseIsSuccessful();
        // Admin #3 -> #1, Regular #2 -> #3, With stripe #1 -> #2, With favorites new
        self::assertCount(1, $crawler->filter('.lb-move.lb-move-up'));
        self::assertCount(2, $crawler->filter('.lb-move.lb-move-down'));
        self::assertCount(1, $crawler->filter('.lb-move.lb-move-new'));
        self::assertSame("▲\u{a0}2", trim($crawler->filter('.lb-move.lb-move-up [aria-hidden="true"]')->text()));
        // A tap anywhere on a row opens the rating card; the name stays a plain link to the profile
        $rows = $crawler->filter('[data-controller="players-card"] tr.lb-row-card[data-action="click->players-card#open"]');
        self::assertCount(4, $rows);
        self::assertStringContainsString('/en/msp-rating/card/', (string) $rows->first()->attr('data-players-card-url-param'));
        self::assertNull($crawler->filter('a.lb-name')->first()->attr('data-action'));
        // The skill tier is the icon next to the name (a lock for a guest), no text line under the rating
        self::assertCount(4, $rows->filter('.lb-solver .rank-icon'));
        self::assertCount(0, $crawler->filter('.lb-table .lb-value-secondary'));
        // This week: the climber and the new entry
        self::assertCount(2, $crawler->filter('.msp-this-week-tile'));
        // Season 0, Season 1 announced
        self::assertStringContainsString('Season 0', $crawler->filter('.msp-season')->text());
        self::assertStringContainsString('Season 1 starts on', $crawler->filter('.msp-season')->text());
    }

    public function testTheViewersOwnMoveIsOnThePositionLine(): void
    {
        $browser = $this->browser('2026-06-21 12:00', PlayerFixture::PLAYER_ADMIN);
        $this->world();

        $crawler = $browser->request('GET', '/en/msp-rating');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('▲ 2 places since', $crawler->filter('[data-testid="lb-position"]')->text());
    }

    public function testTheSeasonAnnouncementEndsWhenTheSeasonStarts(): void
    {
        $browser = $this->browser('2027-01-01 08:00');

        $crawler = $browser->request('GET', '/en/msp-rating');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('starts on', $crawler->filter('.msp-season')->text());
    }

    public function testTheProfileHeaderChipAndTheLazyBlock(): void
    {
        $browser = $this->browser('2026-06-21 12:00');
        $this->world();

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        self::assertResponseIsSuccessful();
        self::assertSame("▲\u{a0}2", trim($crawler->filter('.player-chip-competition .player-chip-move [aria-hidden="true"]')->first()->text()));
        self::assertCount(1, $crawler->filter('turbo-frame#msp-rating-history[loading="lazy"]'));

        // Off the ladder: neither the arrow nor the block
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);
        self::assertCount(0, $crawler->filter('turbo-frame#msp-rating-history'));
    }

    private function browser(string $now, null|string $viewerId = null): KernelBrowser
    {
        $browser = self::createClient();
        self::getContainer()->set(ClockInterface::class, new MockClock(new DateTimeImmutable($now, new DateTimeZone('UTC'))));

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        return $browser;
    }

    /**
     * Today: Admin 1.6 (#1), With stripe 1.5 (#2), Regular 1.4 (#3), With favorites 1.0 (#4).
     * Stored days 7-20 Jun: With stripe 1.55 (#1), Regular 1.45 (#2), Admin 1.3 (#3) - Admin logged a new result since.
     */
    private function world(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        foreach ([PlayerFixture::PLAYER_ADMIN => 1.6, PlayerFixture::PLAYER_WITH_STRIPE => 1.5, PlayerFixture::PLAYER_REGULAR => 1.4, PlayerFixture::PLAYER_WITH_FAVORITES => 1.0] as $playerId => $rating) {
            $connection->executeStatement(
                'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, ' . TestClock::SQL_NOW . ')',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'rating' => $rating],
            );
        }

        for ($day = 7; $day <= 20; $day++) {
            foreach ([PlayerFixture::PLAYER_WITH_STRIPE => [1.55, 1], PlayerFixture::PLAYER_REGULAR => [1.45, 2], PlayerFixture::PLAYER_ADMIN => [1.3, 3]] as $playerId => [$rating, $place]) {
                $connection->executeStatement(
                    'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, elo_rank, computed_at) VALUES (gen_random_uuid(), :player, 500, :day, :rating, :place, ' . TestClock::SQL_NOW . ')',
                    ['player' => $playerId, 'day' => sprintf('2026-06-%02d', $day), 'rating' => $rating, 'place' => $place],
                );
            }
        }

        $connection->executeStatement(
            "INSERT INTO player_rating_entry (player_id, pieces_count, puzzle_id, portfolio, seconds, solved_at, percentile, difficulty_weight, decay, points, in_top, computed_at)
             VALUES (:player, 500, :puzzle, 'first_attempt', 3000, '2026-06-18', 90.0, 1.0, 1.0, 0.9, true, " . TestClock::SQL_NOW . ")",
            ['player' => PlayerFixture::PLAYER_ADMIN, 'puzzle' => PuzzleFixture::PUZZLE_500_01],
        );
    }
}
