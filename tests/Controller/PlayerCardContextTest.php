<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPuzzleResultDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\Navigation\EmptySignedInEndpoint;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The player card's context block (docs/features/navigation CARD-02) and the owner's own card (CARD-04): one block per
 * kind, "you" lines only for a signed-in viewer on somebody else's card, every rank equal to the surface's own (the
 * puzzle leaderboard, the result detail, the round page, the boards), and a context that does not apply - invalid,
 * unknown, secret, awaiting verification, a draft event, a board the viewer may not see - is no block, never a status.
 *
 * Fixtures: on PUZZLE_500_02 PLAYER_REGULAR has 3 solo results (best TIME_08), PLAYER_WITH_FAVORITES the fastest,
 * PLAYER_ADMIN the slowest and PLAYER_PRIVATE one only its friends see; TIME_12 is the PLAYER_REGULAR + PLAYER_PRIVATE
 * pair; PLAYER_ADMIN is "Anna Fast", first in the published Group A of the Results Cup.
 */
final class PlayerCardContextTest extends WebTestCase
{
    use EmptySignedInEndpoint;
    use QueryCountAssertions;

    private const string FRAME = 'player-card';

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyKind(): iterable
    {
        yield 'puzzle' => ['puzzle:' . PuzzleFixture::PUZZLE_500_02];
        yield 'feed' => ['feed:' . PuzzleSolvingTimeFixture::TIME_08];
        yield 'notification' => ['notification:' . PuzzleSolvingTimeFixture::TIME_12];
        yield 'round, official' => ['round:' . OfficialResultsFixture::ROUND_GROUP_A];
        yield 'round, unofficial' => ['round:' . EventsPageFixture::ROUND_SPRINT_1];
        yield 'times board' => ['leaderboard:times-500:cz'];
        yield 'MSP Rating board' => ['leaderboard:msp-500'];
        yield 'level board' => ['leaderboard:xp'];
        yield 'karma board' => ['leaderboard:karma:cz'];
    }

    /**
     * One statement per context, whatever the kind (CARD-02); with the direct-messages check of a player who switched
     * them off the card stays within CARD-06's "≤ 4 more than the empty signed-in endpoint" (CARD-06 pins the numbers)
     */
    #[DataProvider('everyKind')]
    public function testEveryContextIsOneStatement(string $context): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->database()->executeStatement('UPDATE player SET allow_direct_messages = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_ADMIN]);
        $empty = count($this->emptySignedInEndpointStatements($browser));

        $this->card($browser, PlayerFixture::PLAYER_ADMIN);
        $this->startCountingQueries($browser);
        $this->card($browser, PlayerFixture::PLAYER_ADMIN);
        self::assertResponseIsSuccessful();
        $withoutContext = $this->queryCount($browser);

        $this->startCountingQueries($browser);
        $this->card($browser, PlayerFixture::PLAYER_ADMIN, $context);
        self::assertResponseIsSuccessful();
        $withContext = $this->queryCount($browser);

        self::assertSame($withoutContext + 1, $withContext, implode("\n", $this->executedSql($browser)));
        self::assertLessThanOrEqual(4, $withContext - $empty, implode("\n", $this->executedSql($browser)));
    }

    public function testThePuzzleContextIsTheLeaderboardsStandingWithTheViewersGap(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'puzzle:' . PuzzleFixture::PUZZLE_500_02);

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('.players-card-context[data-card-context="puzzle"]');
        self::assertCount(1, $block);
        self::assertSame('On this puzzle', trim($block->filter('h3')->text()));

        // Parity: the rank is the result detail's standing and the puzzle page's row (PuzzleTimes)
        $standing = self::getContainer()->get(GetPuzzleResultDetail::class)->standing(PuzzleFixture::PUZZLE_500_02, 'solo', PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($standing);
        $leaderboardRank = $this->puzzlePageRank($browser, PuzzleFixture::PUZZLE_500_02, PlayerFixture::PLAYER_REGULAR);
        self::assertSame($standing->rank, $leaderboardRank);

        $line = $block->filter('.players-card-context-line')->text();
        self::assertStringContainsString(sprintf('#%d of %d', $standing->rank, $standing->total), $line);
        self::assertStringContainsString('best 00:28:20', $line);
        self::assertStringContainsString('3 attempts', $line);

        // PLAYER_ADMIN's best there is 35:00 - 6:40 slower than PLAYER_REGULAR's 28:20
        self::assertSame('You: 00:35:00 · 06:40 slower', trim($block->filter('.players-card-context-you')->text()));
    }

    public function testAGuestSeesTheContextWithoutAnyYouLine(): void
    {
        $browser = $this->browser();

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'puzzle:' . PuzzleFixture::PUZZLE_500_02);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.players-card-context[data-card-context="puzzle"] .players-card-context-line'));
        self::assertCount(0, $crawler->filter('.players-card-context-you'));
    }

    public function testAViewerWithoutASoloTimeIsToldSo(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        // Results awaiting verification are never a best - the viewer's either
        $this->database()->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => PuzzleFixture::PUZZLE_500_02],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'puzzle:' . PuzzleFixture::PUZZLE_500_02);

        self::assertSame('You: no solo time here yet', trim($crawler->filter('.players-card-context-you')->text()));
    }

    public function testAPlayerWithPairResultsOnlySaysSo(): void
    {
        // PLAYER_REGULAR solved PUZZLE_1000_03 only in the pair TIME_41
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'puzzle:' . PuzzleFixture::PUZZLE_1000_03);

        $block = $crawler->filter('.players-card-context[data-card-context="puzzle"]');
        self::assertSame('Solved here in a pair or team 1×', trim($block->filter('.players-card-context-line')->text()));
        self::assertCount(0, $block->filter('.players-card-context-you'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidContexts(): iterable
    {
        yield 'garbage' => ['bogus'];
        yield 'unknown kind' => ['player:' . PlayerFixture::PLAYER_ADMIN];
        yield 'not a uuid' => ['puzzle:500'];
        yield 'unknown puzzle' => ['puzzle:' . Uuid::uuid7()->toString()];
        yield 'unknown result' => ['feed:' . Uuid::uuid7()->toString()];
        yield 'unknown round' => ['round:' . Uuid::uuid7()->toString()];
        yield 'unknown board' => ['leaderboard:fastest'];
        yield 'unknown country' => ['leaderboard:times-500:zz'];
        yield 'a board nobody of them is on' => ['leaderboard:times-77'];
        yield 'somebody else\'s result' => ['feed:' . PuzzleSolvingTimeFixture::TIME_33];
        yield 'a puzzle the player never solved' => ['puzzle:' . PuzzleFixture::PUZZLE_9000];
    }

    #[DataProvider('invalidContexts')]
    public function testAContextThatDoesNotApplyIsNoBlockAndNoError(string $context): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, $context);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.players-card'));
        self::assertCount(0, $crawler->filter('.players-card-context'));
    }

    public function testASecretPuzzleIsNoBlock(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->database()->executeStatement(
            "UPDATE puzzle SET hide_until = NOW() + INTERVAL '10 days' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_500_02],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'puzzle:' . PuzzleFixture::PUZZLE_500_02);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'feed:' . PuzzleSolvingTimeFixture::TIME_08);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));
    }

    public function testTheFeedContextIsTheResultWithItsStanding(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $standing = self::getContainer()->get(GetPuzzleResultDetail::class)->standing(PuzzleFixture::PUZZLE_500_02, 'solo', PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($standing);

        // The best result: its own rank
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'feed:' . PuzzleSolvingTimeFixture::TIME_08);
        $block = $crawler->filter('.players-card-context[data-card-context="feed"]');
        self::assertSame('This result', trim($block->filter('h3')->text()));
        self::assertSame(sprintf('00:28:20 · #%d of %d on this puzzle', $standing->rank, $standing->total), trim($block->filter('.players-card-context-line')->text()));
        self::assertSame('You: 00:35:00 · 06:40 slower', trim($block->filter('.players-card-context-you')->text()));

        // An earlier, slower result: the best next to it
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'feed:' . PuzzleSolvingTimeFixture::TIME_07);
        self::assertSame(
            sprintf('00:31:40 · best here 00:28:20, #%d of %d', $standing->rank, $standing->total),
            trim($crawler->filter('.players-card-context[data-card-context="feed"] .players-card-context-line')->text()),
        );
    }

    public function testANotificationResultOfAPairRanksThePair(): void
    {
        // TIME_12 = the PLAYER_REGULAR + PLAYER_PRIVATE pair on PUZZLE_1000_01, the only pair there
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'notification:' . PuzzleSolvingTimeFixture::TIME_12);

        $block = $crawler->filter('.players-card-context[data-card-context="notification"]');
        self::assertSame('01:00:00 · #1 of 1 on this puzzle', trim($block->filter('.players-card-context-line')->text()));
        // The gap compares solo times only
        self::assertCount(0, $block->filter('.players-card-context-you'));
    }

    public function testAResultAwaitingVerificationIsOnlyTheOwnersContext(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->database()->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_08],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'feed:' . PuzzleSolvingTimeFixture::TIME_08);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'feed:' . PuzzleSolvingTimeFixture::TIME_08);
        self::assertCount(1, $crawler->filter('.players-card-context[data-card-context="feed"]'));
        // Awaiting verification, it is never a rank: the rank is the best clean result's (TIME_07)
        self::assertStringContainsString('best here 00:31:40', $crawler->filter('.players-card-context-line')->text());
    }

    public function testTheRoundContextIsTheOfficialPlaceOncePublished(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_ADMIN, 'round:' . OfficialResultsFixture::ROUND_GROUP_A);

        $block = $crawler->filter('.players-card-context[data-card-context="round"]');
        self::assertCount(1, $block);
        self::assertStringContainsString('Results Cup', $block->filter('h3')->text());
        self::assertSame('Official result: #1 · 01:00:00 · Qualified', trim($block->filter('.players-card-context-line')->text()));

        // Parity: the public round page puts the same entry first
        $page = $browser->request('GET', '/en/events/results-cup/results/group-a');
        self::assertResponseIsSuccessful();
        self::assertSame('1', $page->filter('tr[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA . '"]')->attr('data-official-rank'));
    }

    /**
     * Review CARD+TEASE F3: once the round shows official results, a puzzler without a ranked official entry who linked
     * a time to it is still in the round page's list of times puzzlers added (folded under the official table) - the
     * card opened from that list says the same, as before publishing
     */
    public function testWithoutARankedOfficialEntryThePublishedRoundShowsTheLinkedTime(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        // PLAYER_REGULAR has no entry in Group A (published): their solo TIME_07 (31:40) linked to the round's puzzle
        $this->database()->executeStatement(
            'UPDATE puzzle_solving_time SET puzzle_id = :puzzle, competition_round_id = :round, suspicious = false WHERE id = :id',
            ['puzzle' => PuzzleFixture::PUZZLE_1000_05, 'round' => OfficialResultsFixture::ROUND_GROUP_A, 'id' => PuzzleSolvingTimeFixture::TIME_07],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'round:' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseIsSuccessful();
        $block = $crawler->filter('.players-card-context[data-card-context="round"]');
        self::assertCount(1, $block);
        self::assertStringContainsString('Results Cup', $block->filter('h3')->text());
        self::assertSame('00:31:40 · added by the puzzler, not official', trim($block->filter('.players-card-context-line')->text()));

        // Parity: the round page shows the official table and, folded under it, this time among the times puzzlers added
        $page = $browser->request('GET', '/en/events/results-cup/results/group-a');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $page->filter('[data-official-results] tr[data-official-entry]')->count());
        $added = $page->filter('details[data-round-player-times] [data-player-card-context="round:' . OfficialResultsFixture::ROUND_GROUP_A . '"]');
        self::assertCount(1, $added);
        $row = $added->filter('tr')->reduce(
            static fn (Crawler $row): bool => $row->filter('a[href="/en/player-profile/' . PlayerFixture::PLAYER_REGULAR . '"]')->count() > 0,
        );
        self::assertCount(1, $row);
        self::assertStringContainsString('00:31:40', $row->text());
        self::assertCount(0, $page->filter('[data-official-results] a[href="/en/player-profile/' . PlayerFixture::PLAYER_REGULAR . '"]'));
    }

    /**
     * The decided rule stays (CARD-02, decisions log 2026-10-13): an official pair/team entry with a member hidden from
     * the viewer is not on the round page for them - the card shows no round line, not even the linked time of another
     * pair the player solved with (it would tell the entry is there)
     */
    public function testAHiddenOfficialTeamEntryShowsNoLineEvenWithALinkedTime(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $database = $this->database();
        // Pairs published; "Edge Hunters" = PLAYER_REGULAR + PLAYER_WITH_FAVORITES (instead of Gina's player); the pair
        // TIME_41 (PLAYER_REGULAR + PLAYER_PRIVATE) linked to the round's puzzle
        $database->executeStatement('UPDATE competition_round SET results_published_at = NOW() WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_PAIRS]);
        $database->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', ['player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'id' => OfficialResultsFixture::PARTICIPANT_GINA]);
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET puzzle_id = :puzzle, competition_round_id = :round WHERE id = :id',
            ['puzzle' => PuzzleFixture::PUZZLE_2000, 'round' => OfficialResultsFixture::ROUND_PAIRS, 'id' => PuzzleSolvingTimeFixture::TIME_41],
        );
        $context = 'round:' . OfficialResultsFixture::ROUND_PAIRS;

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, $context);
        self::assertSame('Official result: #2 · 01:40:00 · Qualified', trim($crawler->filter('.players-card-context[data-card-context="round"] .players-card-context-line')->text()));

        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_ADMIN, 'blocked' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, $context);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));
    }

    public function testAnUnpublishedRoundShowsTheLinkedTimeAsUnofficial(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        // TIME_SPRINT_1: PLAYER_REGULAR's solo time linked to Sprint 1 (no official results) - the round page lists it
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'round:' . EventsPageFixture::ROUND_SPRINT_1);
        $line = trim($crawler->filter('.players-card-context[data-card-context="round"] .players-card-context-line')->text());
        self::assertMatchesRegularExpression('~^\d{2}:\d{2}:\d{2} · added by the puzzler, not official$~', $line);
        $page = $browser->request('GET', '/en/series/moonlight-sprint-league/season-one/results/sprint-1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(explode(' · ', $line)[0], $page->filter('[data-player-card-context="round:' . EventsPageFixture::ROUND_SPRINT_1 . '"]')->text());

        // A round that has not started yet lists nothing - the card neither (TIME_09 is linked to the WJPC qualification)
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'round:' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));

        // Group B is not published: PLAYER_REGULAR ("Hugo Slow") has an entry, but no official line and no linked time
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'round:' . OfficialResultsFixture::ROUND_GROUP_B);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));
    }

    public function testADraftEventsRoundIsNoBlockEvenForItsTeam(): void
    {
        $browser = $this->browser();
        $this->database()->executeStatement(
            'UPDATE competition SET is_draft = true WHERE id = :id',
            ['id' => EventsPageFixture::EDITION_SPRINT_SEASON],
        );

        // An admin, the edition's creator and the player in it see no block - the context never shows a draft
        foreach ([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            TestingLogin::asPlayer($browser, $viewer);
            $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'round:' . EventsPageFixture::ROUND_SPRINT_1);

            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.players-card-context'));
            self::assertStringNotContainsString('Season One', (string) $browser->getResponse()->getContent());
            self::assertStringNotContainsString('Sprint 1', (string) $browser->getResponse()->getContent());
        }
    }

    public function testTheTimesBoardContextIsTheBoardsRow(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'leaderboard:times-500');
        $block = $crawler->filter('.players-card-context[data-card-context="leaderboard"]');
        self::assertSame('Fastest solo times · 500 pieces', trim($block->filter('h3')->text()));

        $boardRank = $this->boardRank($browser, '/en/ladder/solo/500-pieces', PlayerFixture::PLAYER_REGULAR);
        $viewerRank = $this->boardRank($browser, '/en/ladder/solo/500-pieces', PlayerFixture::PLAYER_ADMIN);
        self::assertMatchesRegularExpression(sprintf('~^#%d of \d+ worldwide · \d{2}:\d{2}:\d{2}$~', $boardRank), trim($block->filter('.players-card-context-line')->text()));
        self::assertSame(sprintf('You: #%d', $viewerRank), trim($block->filter('.players-card-context-you')->text()));

        // In one country the rank is the country's
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'leaderboard:times-500:cz');
        $countryRank = $this->boardRank($browser, '/en/ladder/solo/500-pieces/country/cz', PlayerFixture::PLAYER_REGULAR);
        self::assertMatchesRegularExpression(sprintf('~^#%d of \d+ in Czechia · ~', $countryRank), trim($crawler->filter('.players-card-context-line')->text()));
    }

    public function testAPrivateViewerIsNeverOnAGlobalBoard(): void
    {
        // PLAYER_PRIVATE has the fastest 500-piece solo times - global boards stay closed to private players for everybody
        $browser = $this->browser(PlayerFixture::PLAYER_PRIVATE);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_ADMIN, 'leaderboard:times-500');

        self::assertCount(1, $crawler->filter('.players-card-context[data-card-context="leaderboard"] .players-card-context-line'));
        self::assertCount(0, $crawler->filter('.players-card-context-you'));
    }

    public function testTheMspBoardLeavesOutRankingOptOuts(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->rate(PlayerFixture::PLAYER_REGULAR, 0.9);
        $this->rate(PlayerFixture::PLAYER_ADMIN, 0.8);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'leaderboard:msp-500');
        $block = $crawler->filter('.players-card-context[data-card-context="leaderboard"]');
        self::assertSame('MSP Rating · 500 pieces', trim($block->filter('h3')->text()));
        self::assertSame('#1 of 2 worldwide · 900', trim($block->filter('.players-card-context-line')->text()));
        self::assertSame('You: #2', trim($block->filter('.players-card-context-you')->text()));

        $this->database()->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'leaderboard:msp-500');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));
    }

    public function testTheProgressionBoardsFollowTheirGates(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->database()->executeStatement('UPDATE player SET xp_total = 500, level = 5 WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        $this->database()->executeStatement('UPDATE player SET xp_total = 300, level = 3 WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'leaderboard:xp');
        $block = $crawler->filter('.players-card-context[data-card-context="leaderboard"]');
        self::assertSame('Level (XP)', trim($block->filter('h3')->text()));
        self::assertMatchesRegularExpression('~^#1 of \d+ worldwide · 500 XP$~', trim($block->filter('.players-card-context-line')->text()));
        self::assertSame('You: #2', trim($block->filter('.players-card-context-you')->text()));

        // The Achievement Points board asks guests to sign in - no context for them
        $browser->getCookieJar()->clear();
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'leaderboard:ap');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));

        // Before the XP launch only admins see progression
        FixedXpLaunchReadiness::$ready = false;
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'leaderboard:xp');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-context'));
    }

    public function testTheOwnCardOffersTheOwnersActionsPerContext(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_REGULAR);

        // No context: Edit profile and View profile, never Favorite, Compare, Message
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        $actions = $crawler->filter('.players-card-actions');
        self::assertSame('/en/edit-profile', $actions->filter('a[data-nav="card/none/edit_profile"]')->attr('href'));
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $actions->filter('a[data-nav="card/none/view_profile"]')->attr('href'));
        self::assertCount(0, $actions->filter('.players-card-favorite, form, .players-card-message'));
        self::assertCount(0, $actions->filter('[data-nav$="/edit_result"], [data-nav$="/own_results"]'));

        // A feed result they may edit
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'feed:' . PuzzleSolvingTimeFixture::TIME_08);
        $edit = $crawler->filter('.players-card-actions a[data-nav="card/feed/edit_result"]');
        self::assertSame('/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_08, $edit->attr('href'));
        self::assertSame('_top', $edit->attr('data-turbo-frame'));
        self::assertSame('Edit this result', trim($edit->text()));
        self::assertCount(0, $crawler->filter('.players-card-context-you'), 'No "you" line on your own card');

        // A notification about a pair time they took part in - every member may edit it
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'notification:' . PuzzleSolvingTimeFixture::TIME_41);
        self::assertSame('/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_41, $crawler->filter('a[data-nav="card/notification/edit_result"]')->attr('href'));

        // A puzzle: their results there, opened over the card (the best solo result's detail)
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'puzzle:' . PuzzleFixture::PUZZLE_500_02);
        $own = $crawler->filter('.players-card-actions a[data-nav="card/puzzle/own_results"]');
        self::assertSame('/en/result/' . PuzzleSolvingTimeFixture::TIME_08, $own->attr('href'));
        self::assertSame('modal-frame', $own->attr('data-turbo-frame'));
        self::assertSame('Your results on this puzzle', trim($own->text()));
        self::assertCount(1, $crawler->filter('a[data-nav="card/puzzle/edit_profile"]'));
        self::assertCount(1, $crawler->filter('a[data-nav="card/puzzle/view_profile"]'));
        self::assertCount(0, $crawler->filter('.players-card-favorite, .players-card-actions form, .players-card-message'));
    }

    public function testSomebodyElsesCardNeverHasTheOwnersActions(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR, 'feed:' . PuzzleSolvingTimeFixture::TIME_08);

        self::assertCount(0, $crawler->filter('.players-card-own'));
        self::assertCount(1, $crawler->filter('.players-card-favorite'));
    }

    private function browser(null|string $viewerId = null): KernelBrowser
    {
        $browser = self::createClient();

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        return $browser;
    }

    private function card(KernelBrowser $browser, string $playerId, null|string $context = null): Crawler
    {
        $query = $context !== null ? '?context=' . rawurlencode($context) : '';

        return $browser->request('GET', '/en/puzzler-card/' . $playerId . $query, server: ['HTTP_TURBO_FRAME' => self::FRAME]);
    }

    /**
     * The rank in the player's row of the puzzle page's leaderboard (PuzzleTimes), as the viewer sees it
     */
    private function puzzlePageRank(KernelBrowser $browser, string $puzzleId, string $playerId): int
    {
        $page = $browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();
        $row = $page->filter('tr.lb-row')->reduce(
            static fn (Crawler $row): bool => $row->filter('a.lb-name[href="/en/player-profile/' . $playerId . '"]')->count() > 0,
        );
        self::assertCount(1, $row);

        return (int) trim($row->filter('.lb-rank')->text());
    }

    /**
     * The rank in the player's row of a Times board page, as the viewer sees it
     */
    private function boardRank(KernelBrowser $browser, string $url, string $playerId): int
    {
        $page = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $row = $page->filter('tr')->reduce(
            static fn (Crawler $row): bool => $row->filter('a.lb-name[href="/en/player-profile/' . $playerId . '"]')->count() > 0,
        );
        self::assertCount(1, $row, $url);

        return (int) trim($row->filter('.lb-rank')->text());
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function rate(string $playerId, float $rating): void
    {
        $this->database()->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'rating' => $rating],
        );
    }
}
