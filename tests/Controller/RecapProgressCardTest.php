<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Services\AnnouncementModals\ResolveAnnouncementModal;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The one progress card on the recap after logging a time (docs/features/xp-levels/launch-2026-10.md, "After logging
 * a time"). Viewers are admins while the xp-system gate is not launch ready - PLAYER_ADMIN is also a member and owns
 * TIME_03 (PUZZLE_500_01, solo, 500 pieces).
 */
final class RecapProgressCardTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testMemberSeesLevelPlaceAndCompetitionInOneCard(): void
    {
        $browser = self::createClient();
        $this->giveXp(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, [3, 2], total: 50);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('section.recap-card');
        self::assertCount(1, $card);

        $level = $card->filter('[data-testid="recap-level"]');
        // Curve v7: 50 XP is Level 4 (41), 45 before this time - no level-up
        self::assertStringContainsString('Level 4', $level->text());
        self::assertStringContainsString('+5 XP', $level->text());
        self::assertStringContainsString('9 of 19 XP to Level 5', $level->text());
        self::assertCount(1, $level->filter('a.recap-row[href="/en/my/xp-history"]'));
        // The receipt is one tap away, not on the page
        self::assertCount(1, $level->filter('details.recap-receipt li.is-total'));
        self::assertCount(0, $level->filter('[data-testid="recap-level-up"]'));

        // The player's best time on the puzzle leaderboard
        self::assertMatchesRegularExpression('/^#\d+ of \d+ on this puzzle$/', $card->filter('[data-testid="recap-place"] .recap-row-title')->text());

        // 500 pieces has a rating and a skill tier; the countdown starts from PlayerSkillCalculator::MINIMUM_QUALIFYING_PUZZLES
        $competition = $card->filter('[data-testid="recap-competition"]');
        self::assertStringContainsString('MSP Rating: ', $competition->text());
        self::assertStringContainsString('10 more qualifying puzzles to your skill tier', $competition->text());

        // Counted - nothing polls any more, and the old blocks are gone
        self::assertCount(0, $crawler->filter('[data-poll]'));
        self::assertCount(0, $crawler->filter('.xp-levelup-overlay, .toast'));
        self::assertStringNotContainsString('Your Overall Progress', $crawler->text());
    }

    public function testNoCompetitionLineForPieceCountsWithoutARating(): void
    {
        $browser = self::createClient();
        // TIME_12 is a 1000-piece pair time: no place, no rating line - only the members' own level
        $this->makeAdmin(PlayerFixture::PLAYER_PRIVATE);
        $this->giveXp(PlayerFixture::PLAYER_PRIVATE, PuzzleSolvingTimeFixture::TIME_12, [7], total: 7);
        $this->giveXp(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_12, [3], total: 3);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_12);

        self::assertResponseIsSuccessful();
        // Every registered member of a pair sees their own XP, not the tracker's
        self::assertStringContainsString('+7 XP', $crawler->filter('[data-testid="recap-level"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="recap-place"]'));
        self::assertCount(0, $crawler->filter('[data-testid="recap-competition"], [data-testid="recap-competition-locked"]'));
    }

    public function testCountingPollsWithBackoffAndGivesUpWithoutHangingForever(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        self::assertSame('delay(1200)|checkProgress', $crawler->filter('[data-poll]')->attr('data-poll'));
        self::assertStringContainsString('Counting your XP', $crawler->filter('section.recap-card')->text());

        $component = $this->createLiveComponent('XpRecapCelebration', ['solvingTimeId' => PuzzleSolvingTimeFixture::TIME_03], $browser);
        $component->setRouteLocale('en');

        $component->call('checkProgress');
        self::assertSame('delay(2000)|checkProgress', $component->render()->crawler()->filter('[data-poll]')->attr('data-poll'));

        for ($i = 0; $i < 5; $i++) {
            $component->call('checkProgress');
        }

        $crawler = $component->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-poll]'));
        self::assertStringContainsString('still being counted', $crawler->text());

        // The XP lands: shown on the next render, no further polling
        $this->giveXp(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, [5], total: 5);
        $component = $this->createLiveComponent('XpRecapCelebration', ['solvingTimeId' => PuzzleSolvingTimeFixture::TIME_03], $browser);
        $component->setRouteLocale('en');
        $crawler = $component->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-poll]'));
        self::assertStringContainsString('+5 XP', $crawler->text());
    }

    /**
     * SavedTimeProgression: the save settles the saving player's achievements and XP before it redirects, so the recap
     * shows them at its first render - no "Counting your XP", no polling. The queued messages stay the safety net.
     */
    public function testASavedTimeLandsOnARecapThatAlreadyShowsItsXp(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $token = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($token);
        $timeId = Uuid::uuid7()->toString();

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => [
                '_token' => $token,
                'mode' => 'speed_puzzling',
                'puzzle' => PuzzleFixture::PUZZLE_500_04,
                'timeHours' => '0',
                'timeMinutes' => '58',
                'timeSeconds' => '0',
                'finishedAt' => self::getContainer()->get(ClockInterface::class)->now()->format('d.m.Y'),
                'collection' => '__system_collection__',
            ],
            'time_id' => $timeId,
        ]);
        self::assertResponseRedirects('/en/time-added/' . $timeId);

        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);
        $sent = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        );
        self::assertContains(AwardXpForSolvingTime::class, $sent);
        self::assertContains(RecalculateBadgesForPlayer::class, $sent);

        $crawler = $browser->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-poll]'));
        self::assertStringNotContainsString('Counting your XP', $crawler->filter('section.recap-card')->text());
        self::assertMatchesRegularExpression('/\+\d+ XP/', $crawler->filter('[data-testid="recap-level"]')->text());
    }

    public function testLevelUpIsShownAndCelebratesOnlyRightAfterTheSave(): void
    {
        $browser = self::createClient();
        // 25 XP in total, 22 of them from this time: Level 1 -> Level 3 (curve v7: Level 3 = 23 XP)
        $this->giveXp(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, [22], total: 25);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // An old time (back from editing it, a bookmark): the pill, but no celebration
        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);
        self::assertCount(1, $crawler->filter('[data-testid="recap-level-up"]'));
        self::assertCount(0, $crawler->filter('[data-controller="recap-celebration"]'));

        $this->saveNow(PuzzleSolvingTimeFixture::TIME_03);
        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        $level = $crawler->filter('[data-controller="recap-celebration"]');
        self::assertCount(1, $level);
        self::assertSame(PuzzleSolvingTimeFixture::TIME_03, $level->attr('data-recap-celebration-time-id-value'));
        self::assertSame('1', $level->attr('data-recap-celebration-from-value'));
        self::assertSame('3', $level->attr('data-recap-celebration-to-value'));
        // Not a milestone level: no sheet, never a full-screen overlay
        self::assertCount(0, $crawler->filter('[data-testid="recap-milestone-sheet"]'));
        self::assertStringContainsString('Level 3!', $crawler->filter('section.recap-card [aria-live="polite"]')->text());
    }

    public function testMilestoneLevelGetsABottomSheetTheBrowserOpensOnce(): void
    {
        $browser = self::createClient();
        $this->giveXp(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, [6], total: LevelTable::xpForLevel(10) + 2);
        $this->saveNow(PuzzleSolvingTimeFixture::TIME_03);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        $sheet = $crawler->filter('[data-testid="recap-milestone-sheet"]');
        self::assertCount(1, $sheet);
        // Rendered closed: recap_celebration_controller.js opens it once per time id, so a reload never shows it again
        self::assertNotNull($sheet->attr('hidden'));
        self::assertSame('false', $sheet->attr('aria-modal'));
        self::assertCount(1, $sheet->filter('button[data-action="recap-celebration#close"]'));
    }

    public function testAMemberRevealsTheAchievementThisTimeEarnedOnce(): void
    {
        $browser = self::createClient();
        $this->giveXp(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, [5], total: 5);
        $earned = $this->earnWithTime(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, 'puzzles_solved', 3);
        // Earned long before this time: not this time's achievement
        $this->earn(PlayerFixture::PLAYER_ADMIN, 'streak', 1, '2020-01-01 10:00:00');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        $reveal = $crawler->filter('section.recap-card [data-testid="recap-achievement-reveal"]');
        self::assertCount(1, $reveal);
        self::assertStringContainsString('is-big', (string) $reveal->attr('class'));
        $stage = $reveal->filter('button.lpr-stage');
        self::assertSame('/en/badges/' . $earned . '/reveal', $stage->attr('data-last-piece-reveal-url-param'));
        self::assertSame('3', $stage->attr('data-last-piece-reveal-look-param'));
        self::assertStringContainsString('+25 AP', $reveal->text());
        self::assertCount(0, $crawler->filter('img[src*="streak-t1"]'));
        // Achievements grant no XP: no achievement line in the receipt
        self::assertStringNotContainsString('Achievement earned', $crawler->filter('details.recap-receipt')->text());

        // Revealed (the first tap POSTs reveal_badge): a reload shows the badge, no puzzle to solve again
        $browser->request('POST', '/en/badges/' . $earned . '/reveal');
        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        self::assertCount(0, $crawler->filter('[data-testid="recap-achievement-reveal"]'));
        $row = $crawler->filter('a[data-testid="recap-achievement-revealed"]');
        self::assertSame('/en/achievements/puzzles_solved', $row->attr('href'));
        self::assertCount(1, $row->filter('img[src*="puzzles_solved-t3"]'));
    }

    public function testAFreePlayerLearnsThereIsAnAchievementButNotWhich(): void
    {
        $browser = self::createClient();
        $this->dropMembership(PlayerFixture::PLAYER_ADMIN);
        $this->giveXp(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, [5], total: 5);
        $this->earnWithTime(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, 'puzzles_solved', 2);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        $tease = $crawler->filter('[data-testid="recap-achievement-tease"]');
        self::assertCount(1, $tease);
        self::assertStringContainsString('You earned an achievement with this time. Become a member to find out which.', $tease->text());
        self::assertCount(1, $tease->filter('a[href="/en/membership"]'));
        self::assertCount(0, $crawler->filter('[data-testid="recap-achievements"], img.badge-art'));
        self::assertCount(0, $crawler->filter('.recap-achievement-name'));

        // Rating and skill tier: one locked line, no blurred mock in hard-coded English
        self::assertCount(1, $crawler->filter('[data-testid="recap-competition-locked"]'));
        self::assertCount(0, $crawler->filter('[data-testid="recap-competition"]'));
        self::assertStringNotContainsString('Top 18% of players', $crawler->text());
    }

    public function testAFreePlayerWithoutANewAchievementIsNotTeased(): void
    {
        $browser = self::createClient();
        $this->dropMembership(PlayerFixture::PLAYER_ADMIN);
        $this->giveXp(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_03, [5], total: 5);
        $this->earn(PlayerFixture::PLAYER_ADMIN, 'streak', 2, '2020-01-01 10:00:00');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_03);

        self::assertCount(1, $crawler->filter('[data-testid="recap-level"]'));
        self::assertCount(0, $crawler->filter('[data-testid="recap-achievement-tease"]'));
        self::assertStringNotContainsString('waiting achievement', $crawler->text());
    }

    public function testWithoutTheProgressionTheRecapShowsOnlyThePlace(): void
    {
        // Before the XP launch (the switch off): only admins see progression
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();
        // PLAYER_REGULAR is no admin: the xp-system gate hides every XP and achievement trace
        $this->giveXp(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_01, [5], total: 5);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="recap-level"], [data-poll], #xp-solve-receipt'));
        self::assertCount(1, $crawler->filter('section.recap-card [data-testid="recap-place"]'));

        // The tracking recap has no place: no card at all
        $crawler = $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT);
        self::assertCount(0, $crawler->filter('section.recap-card'));
    }

    public function testTrackingRecapIsQuietAndShowsTheCardAfterTheActions(): void
    {
        self::assertContains('added_tracking_recap', ResolveAnnouncementModal::QUIET_ROUTES);

        $browser = self::createClient();
        $this->makeAdmin(PlayerFixture::PLAYER_REGULAR);
        $this->giveXp(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT, [2], total: 2);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT);

        self::assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();
        $actions = strpos($html, 'class="recap-actions');
        $card = strpos($html, 'class="recap-card');
        self::assertNotFalse($actions);
        self::assertNotFalse($card);
        self::assertGreaterThan($actions, $card, 'Same order as added_time_recap: the actions first, then the card');
        self::assertStringContainsString('+2 XP', $html);
    }

    /**
     * @param list<int> $amounts
     */
    private function giveXp(string $playerId, string $timeId, array $amounts, int $total): void
    {
        $connection = self::getContainer()->get(Connection::class);

        foreach ($amounts as $index => $amount) {
            $connection->executeStatement(
                "INSERT INTO xp_entry (id, player_id, amount, reason, in_weekly_delta, earned_at, created_at, solving_time_id)
                 VALUES (:id, :player, :amount, :reason, true, NOW(), NOW(), :time)",
                [
                    'id' => Uuid::uuid7()->toString(),
                    'player' => $playerId,
                    'amount' => $amount,
                    'reason' => $index === 0 ? 'solve_base' : 'solve_weekly_boost',
                    'time' => $timeId,
                ],
            );
        }

        $connection->executeStatement(
            'UPDATE player SET xp_total = :total, level = :level WHERE id = :player',
            ['total' => $total, 'level' => LevelTable::levelForXp($total), 'player' => $playerId],
        );
    }

    private function saveNow(string $timeId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle_solving_time SET tracked_at = NOW() - INTERVAL '1 minute' WHERE id = :time",
            ['time' => $timeId],
        );
    }

    private function earnWithTime(string $playerId, string $timeId, string $type, int $tier): string
    {
        $id = Uuid::uuid7()->toString();

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier)
             SELECT :id, :player, :type, tracked_at + INTERVAL '20 seconds', :tier FROM puzzle_solving_time WHERE id = :time",
            ['id' => $id, 'player' => $playerId, 'type' => $type, 'tier' => $tier, 'time' => $timeId],
        );

        return $id;
    }

    private function earn(string $playerId, string $type, int $tier, string $earnedAt): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (:id, :player, :type, :earnedAt, :tier)',
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => $type, 'earnedAt' => $earnedAt, 'tier' => $tier],
        );
    }

    private function dropMembership(string $playerId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM membership WHERE player_id = :player',
            ['player' => $playerId],
        );
    }

    private function makeAdmin(string $playerId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_admin = true WHERE id = :player',
            ['player' => $playerId],
        );
    }
}
