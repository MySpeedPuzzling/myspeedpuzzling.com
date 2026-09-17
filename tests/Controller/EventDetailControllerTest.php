<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
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

    public function testEventPuzzlesShowTheirRoundInScheduleOrder(): void
    {
        $browser = self::createClient();

        // Final Round (+32 days) puzzle is tagged first, Qualification Round (+30 days) puzzle second -
        // the page must still list the qualification puzzle first
        $connection = self::getContainer()->get(Connection::class);
        foreach ([PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_500_01] as $puzzleId) {
            $connection->executeStatement(
                'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
                ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => $puzzleId],
            );
        }

        $crawler = $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01, 'Qualification Round');
        $this->assertSelectorTextContains('#puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_01, 'Final Round');
        // Each round badge links to that round's results
        $this->assertSelectorExists('#puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01 . ' a[href="/en/events/wjpc-2024/results/qualification-round"]');

        $order = $crawler->filter('[id^="puzzle-list-item-"]')->each(
            static fn ($item): string => (string) $item->attr('id'),
        );
        self::assertSame([
            'puzzle-list-item-' . PuzzleFixture::PUZZLE_500_01,
            'puzzle-list-item-' . PuzzleFixture::PUZZLE_1000_01,
        ], $order);
    }

    public function testRoundChipsAreShownWhenParticipantsAreAssignedToRounds(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-live-round-id-param="' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION . '"]');
    }

    public function testRoundChipsAreHiddenWhenNobodyIsAssignedToARound(): void
    {
        $browser = self::createClient();

        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM competition_participant_round');

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-live-round-id-param]');
    }

    private static function addTimeLinkSelector(string $competitionId): string
    {
        return sprintf('a[href$="?competition=%s"]', $competitionId);
    }
}
