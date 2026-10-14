<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\Navigation\EmptySignedInEndpoint;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What one open of the player card costs (docs/features/navigation README §4.8 "Budget", CARD-06): the page it is opened
 * on pays nothing (server-rendered attributes only - its own budget test), an open pays at most 4 statements more than
 * an empty signed-in endpoint (EmptySignedInEndpoint, measured in the same test): the player's profile row
 * (GetPlayerProfile::byId(), which turns hidden players away), the card with its latest puzzles (GetPlayerCard), the
 * context block of the page it was opened from (GetPlayerCardContext - one statement per kind, none when the kind
 * does not apply to the viewer) and the direct-messages check, only when the player switched direct messages off
 * (PlayerActionsResolver). A guest's card without a context stays 2 (PlayersPageQueryBudgetTest).
 *
 * Every row is pinned exactly: numbers only go down, each change commented with its requirement ID.
 */
final class PlayerCardQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;
    use EmptySignedInEndpoint;

    // README §4.8: ≤ 4 statements per open more than the empty signed-in endpoint
    private const int MAX_OVER_EMPTY = 4;

    // The card's player: PLAYER_ADMIN ("Anna Fast") has a result on PUZZLE_500_02 (TIME_31), first place in the
    // published Group A, a place on every board; PLAYER_WITH_STRIPE is the member who opens it
    private const string PLAYER = PlayerFixture::PLAYER_ADMIN;
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;

    private const string GUEST = 'guest';
    private const string SIGNED_IN = 'member';
    private const string DMS_OFF = 'member, the player\'s direct messages off';

    /**
     * context, viewer, the statements of the request
     *
     * @return iterable<string, array{null|string, string, int}>
     */
    public static function provideOpens(): iterable
    {
        $contexts = [
            'no context' => [null, 0],
            'puzzle' => ['puzzle:' . PuzzleFixture::PUZZLE_500_02, 1],
            'feed result' => ['feed:' . PuzzleSolvingTimeFixture::TIME_31, 1],
            'notification result' => ['notification:' . PuzzleSolvingTimeFixture::TIME_31, 1],
            'round, official' => ['round:' . OfficialResultsFixture::ROUND_GROUP_A, 1],
            'round, unofficial' => ['round:' . EventsPageFixture::ROUND_SPRINT_1, 1],
            'times board' => ['leaderboard:times-500', 1],
            'times board, a country' => ['leaderboard:times-500:cz', 1],
            'MSP Rating board' => ['leaderboard:msp-500', 1],
            'level board' => ['leaderboard:xp', 1],
            'karma board' => ['leaderboard:karma', 1],
            'achievement points board' => ['leaderboard:ap', 1],
            // Not a context: no block, no statement
            'an invalid context' => ['leaderboard:everything', 0],
        ];

        foreach ($contexts as $name => [$context, $contextStatements]) {
            // The profile + the card (+ the context): no user refresh, no profile row of a viewer
            $guest = 2 + $contextStatements;

            // The Achievement Points board asks guests to sign in - no place on a guest's card, no statement
            if ($context === 'leaderboard:ap') {
                $guest = 2;
            }

            yield $name . ', guest' => [$context, self::GUEST, $guest];
            // The user refresh + the viewer's profile row (the empty endpoint) + the profile + the card (+ the context)
            yield $name . ', member' => [$context, self::SIGNED_IN, 4 + $contextStatements];
            // + HasExistingConversation::acceptedBetween()
            yield $name . ', ' . self::DMS_OFF => [$context, self::DMS_OFF, 5 + $contextStatements];
        }
    }

    #[DataProvider('provideOpens')]
    public function testACardOpenCostsAtMostFourStatementsMoreThanAnEmptyEndpoint(null|string $context, string $viewer, int $statements): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MEMBER);
        $empty = $this->emptySignedInEndpointStatements($browser);

        if ($viewer === self::GUEST) {
            $browser->getCookieJar()->clear();
        }

        if ($viewer === self::DMS_OFF) {
            self::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE player SET allow_direct_messages = false WHERE id = :id',
                ['id' => self::PLAYER],
            );
        }

        $url = '/en/puzzler-card/' . self::PLAYER . ($context !== null ? '?context=' . rawurlencode($context) : '');
        $frame = ['HTTP_TURBO_FRAME' => 'player-card'];

        // The second request: the first warms the caches
        $browser->request('GET', $url, server: $frame);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url, server: $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#players-card-name');

        $executed = $this->executedSql($browser);
        self::assertCount($statements, $executed, "{$viewer}, " . ($context ?? 'no context') . ":\n" . implode("\n", $executed));
        self::assertLessThanOrEqual(count($empty) + self::MAX_OVER_EMPTY, count($executed));

        if ($viewer !== self::GUEST) {
            self::assertSame($empty, array_slice($executed, 0, count($empty)), 'The same user refresh and profile row first');
        }
    }
}
