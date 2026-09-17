<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
    }

    public function testAddMyTimeLinkIsShownToLoggedInPlayerOnStartedEvent(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Euro Jigsaw Jam is approved and live today
        $browser->request('GET', '/en/events/euro-jigsaw-jam');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_RECURRING_ONLINE));
    }

    public function testAddMyTimeLinkIsHiddenFromAnonymousVisitor(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/euro-jigsaw-jam');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_RECURRING_ONLINE));
    }

    public function testAddMyTimeLinkIsHiddenOnUpcomingEvent(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // WJPC 2024 starts in 30 days
        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testMemberSeesDifficultyOfEventPuzzles(): void
    {
        $browser = self::createClient();

        // Event puzzles are the ones carrying the event's tag - no fixture puzzle has one
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );
        $connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, computed_at)
             VALUES (:puzzleId, 1.4, :tier, 'high', 30, NOW())
             ON CONFLICT (puzzle_id) DO UPDATE SET difficulty_tier = EXCLUDED.difficulty_tier",
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01, 'tier' => DifficultyTier::Hard->value],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('#puzzle-list-item-%s use[href$="#diff-hard"]', PuzzleFixture::PUZZLE_500_01));
    }

    private static function addTimeLinkSelector(string $competitionId): string
    {
        return sprintf('a[href$="?competition=%s"]', $competitionId);
    }
}
