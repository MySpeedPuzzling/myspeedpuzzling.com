<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The "This week" section of the Players page (docs/features/players-page/README.md): On a roll with moment chips.
 * Countries: PLAYER_REGULAR (John Doe) and PLAYER_ADMIN cz, PLAYER_WITH_FAVORITES de, PLAYER_WITH_STRIPE (Sarah
 * Williams) gb, PLAYER_PRIVATE (Jane Smith) us and private.
 */
final class PlayersThisWeekTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testTheWorldShowsWhoIsOnARollWithTheirMoments(): void
    {
        $browser = self::createClient();
        $this->prepareWeeks($browser, [
            PlayerFixture::PLAYER_WITH_STRIPE => [9, 4500],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
            PlayerFixture::PLAYER_PRIVATE => [20, 10000],
        ]);
        $this->replaceMoments($browser, PlayerFixture::PLAYER_WITH_STRIPE, [
            ['personal_best', 'pb:test', 500, 2300, 2],
            ['pieces_milestone', 'pieces:1000000', null, 1_000_000, 3],
        ]);

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#players-week-title', 'This week');

        $cards = $crawler->filter('.players-week-card');
        self::assertCount(2, $cards, 'Private players are never listed');
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $cards->eq(0)->text());
        self::assertSame('9 puzzles this week 4,500 pieces', $cards->eq(0)->filter('.players-week-line')->text());
        self::assertSame(
            ['New best on 500 pieces', '1M pieces placed'],
            $cards->eq(0)->filter('.players-person-chip')->each(static fn ($chip): string => $chip->text()),
        );
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME, $cards->eq(1)->text());
        self::assertStringNotContainsString('Jane Smith', $crawler->filter('.players-week')->text());

        // The person opens the player card, like everywhere on the page
        self::assertCount(1, $cards->eq(0)->filter('a.players-person[data-action="players-card#open"]'));
    }

    public function testACountryHasItsOwnHeadingAndPeople(): void
    {
        $browser = self::createClient();
        $this->prepareWeeks($browser, [
            PlayerFixture::PLAYER_WITH_STRIPE => [9, 4500],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
        ]);

        $crawler = $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#players-week-title', 'This week in Czechia');
        self::assertCount(1, $crawler->filter('.players-week-card'));
        self::assertSelectorTextContains('.players-week-card', PlayerFixture::PLAYER_REGULAR_NAME);
    }

    public function testAQuietScopeRendersNothing(): void
    {
        $browser = self::createClient();
        $this->prepareWeeks($browser, [PlayerFixture::PLAYER_REGULAR => [5, 2500]]);

        $browser->request('GET', '/en/puzzlers?scope=fr');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.players-week');
        self::assertSelectorNotExists('#players-week-title');
    }

    public function testShowMoreOnlyWhenPhonesHideSomebody(): void
    {
        $browser = self::createClient();
        $this->prepareWeeks($browser, [
            PlayerFixture::PLAYER_WITH_STRIPE => [9, 4500],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
            PlayerFixture::PLAYER_ADMIN => [4, 2000],
        ]);

        $browser->request('GET', '/en/puzzlers');
        self::assertSelectorCount(3, '.players-week-card');
        self::assertSelectorNotExists('.players-week-more');

        $this->prepareWeeks($browser, [
            PlayerFixture::PLAYER_WITH_STRIPE => [9, 4500],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
            PlayerFixture::PLAYER_ADMIN => [4, 2000],
            PlayerFixture::PLAYER_WITH_FAVORITES => [1, 500],
        ]);

        $browser->request('GET', '/en/puzzlers');
        self::assertSelectorCount(4, '.players-week-card');
        self::assertSelectorExists('.players-week[data-controller~="show-more-recent-activity"] .players-week-list[data-show-more-recent-activity-target="list"]');
        self::assertSelectorExists('.players-week-more[data-action="show-more-recent-activity#revealRows"]');
    }

    public function testASignedInPlayerDoesNotSeeTheirBlockedPlayer(): void
    {
        $browser = self::createClient();
        $this->prepareWeeks($browser, [
            PlayerFixture::PLAYER_PRIVATE => [20, 10000],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
        ]);
        // UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE - public here, so only the block keeps her out
        $this->connection($browser)->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.players-week-card'));
        self::assertStringNotContainsString('Jane Smith', $crawler->filter('.players-week')->text());

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertCount(2, $crawler->filter('.players-week-card'));
        self::assertStringContainsString('Jane Smith', $crawler->filter('.players-week')->text());
    }

    public function testTheSectionCostsOneStatement(): void
    {
        $browser = self::createClient();
        $this->prepareWeeks($browser, [
            PlayerFixture::PLAYER_WITH_STRIPE => [9, 4500],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
        ]);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertCount(1, array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'player_moment') || str_contains($sql, 'on_a_roll'),
        ));
    }

    /**
     * Rebuilds the precomputed tables, then sets the week of the given players - everybody else had a quiet one.
     *
     * @param array<string, array{int, int}> $weeks player id => [puzzles, pieces] of the last 7 days
     */
    private function prepareWeeks(KernelBrowser $browser, array $weeks): void
    {
        $container = $browser->getContainer();
        ($container->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        $database = $this->connection($browser);
        $database->executeStatement('UPDATE community_player_stats SET solves7d = 0, pieces7d = 0');

        foreach ($weeks as $playerId => [$puzzles, $pieces]) {
            $database->executeStatement(
                'UPDATE community_player_stats SET solves7d = :puzzles, pieces7d = :pieces WHERE player_id = :id',
                ['puzzles' => $puzzles, 'pieces' => $pieces, 'id' => $playerId],
            );
        }
    }

    /**
     * @param list<array{string, string, null|int, null|int, int}> $moments [type, dedupe key, pieces count, value, days ago]
     */
    private function replaceMoments(KernelBrowser $browser, string $playerId, array $moments): void
    {
        $database = $this->connection($browser);
        $database->executeStatement('DELETE FROM player_moment WHERE player_id = :id', ['id' => $playerId]);

        foreach ($moments as [$type, $dedupeKey, $piecesCount, $value, $daysAgo]) {
            $occurredAt = (new DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d H:i:s');
            $database->executeStatement(
                'INSERT INTO player_moment (id, player_id, type, dedupe_key, occurred_at, solving_time_id, pieces_count, value, previous_value, detected_at)
                 VALUES (:id, :player, :type, :key, :occurred, NULL, :pieces, :value, NULL, :occurred)',
                [
                    'id' => Uuid::uuid7()->toString(),
                    'player' => $playerId,
                    'type' => $type,
                    'key' => $dedupeKey,
                    'occurred' => $occurredAt,
                    'pieces' => $piecesCount,
                    'value' => $value,
                ],
            );
        }
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
