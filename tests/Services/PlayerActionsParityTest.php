<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Component\PlayerHeader;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\PlayerActionsResolver;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\PlayerActions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Favorite, Compare, Message and the tier chip are decided once (PlayerActionsResolver, docs/features/navigation
 * CARD-01c): the player header's props and the player card's markup say what the resolver says, for every kind of
 * viewer. Fixtures: PLAYER_ADMIN and PLAYER_WITH_STRIPE are members; PLAYER_REGULAR blocks PLAYER_PRIVATE
 * (UserBlockFixture); PLAYER_PRIVATE allows PLAYER_WITH_FAVORITES.
 *
 * @phpstan-type Setup array{dmsOff?: bool, conversation?: bool, rankingOptOut?: bool, playerBlocksViewer?: bool}
 */
final class PlayerActionsParityTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return iterable<string, array{null|string, string, Setup}>
     */
    public static function cases(): iterable
    {
        yield 'guest' => [null, PlayerFixture::PLAYER_WITH_STRIPE, []];
        yield 'member' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, []];
        yield 'non-member' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, []];
        yield 'own' => [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_STRIPE, []];
        // The blocked side sees a stranger's actions - it must never be able to tell (docs/features/player-blocklist.md)
        yield 'viewer blocked by the player' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, ['playerBlocksViewer' => true]];
        yield 'direct messages off' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, ['dmsOff' => true]];
        yield 'direct messages off, accepted conversation' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, ['dmsOff' => true, 'conversation' => true]];
        yield 'opted out of rankings' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, ['rankingOptOut' => true]];
        yield 'private profile, viewer on her allow list' => [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE, []];
    }

    /**
     * @param Setup $setup
     */
    #[DataProvider('cases')]
    public function testTheHeaderAndTheCardSayWhatTheResolverSays(null|string $viewerId, string $playerId, array $setup): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $this->prepare($viewerId, $playerId, $setup);

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        $card = $browser->request('GET', '/en/puzzler-card/' . $playerId, server: ['HTTP_TURBO_FRAME' => 'player-card']);
        self::assertResponseIsSuccessful();

        $container = self::getContainer();

        if ($viewerId === null) {
            TestingViewer::signOut($container);
        } else {
            TestingViewer::signIn($container, $viewerId);
        }

        $player = $container->get(GetPlayerProfile::class)->byId($playerId);
        $viewer = $container->get(RetrieveLoggedUserProfile::class)->getProfile();
        $actions = $container->get(PlayerActionsResolver::class)->resolve($player, $viewer);

        $header = $this->mountTwigComponent('PlayerHeader', ['player' => $player]);
        self::assertInstanceOf(PlayerHeader::class, $header);

        // The header's props
        self::assertSame($actions->isOwn, $header->isOwnProfile, 'own');
        self::assertSame($actions->isInViewersFavorites(), $header->isInViewersFavorites, 'favorite');
        self::assertSame($actions->compare, $header->compare, 'compare');
        self::assertSame($actions->comparisonRef, $header->comparisonRef, 'comparison ref');
        self::assertSame($actions->canMessage, $header->canMessage, 'message');
        self::assertSame($actions->tierChip, $header->tierChip, 'tier chip');

        // The card's markup
        self::assertSame([
            'favorite' => $actions->favorite,
            'compare' => $actions->compare,
            'message' => $actions->canMessage,
            'tier' => $actions->tierChip,
            'level' => $actions->showLevel,
        ], self::cardActions($card), 'card');

        // And what each case means
        $expected = self::expected($viewerId, $playerId, $setup);

        foreach ($expected as $property => $value) {
            self::assertSame($value, $actions->{$property}, $property);
        }

        // Block is the profile's only (D12)
        self::assertSame($viewerId !== null && $viewerId !== $playerId, $actions->canBlock);
        self::assertStringNotContainsString('blockPlayerModal', (string) $browser->getResponse()->getContent());
    }

    /**
     * Blocked by the viewer: no header, no card - 404 before any action is decided
     */
    public function testAPlayerTheViewerBlockedHasNeitherHeaderNorCard(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzler-card/' . PlayerFixture::PLAYER_PRIVATE, server: ['HTTP_TURBO_FRAME' => 'player-card']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param Setup $setup
     */
    private function prepare(null|string $viewerId, string $playerId, array $setup): void
    {
        $database = self::getContainer()->get(Connection::class);

        if (($setup['dmsOff'] ?? false) === true) {
            $database->executeStatement('UPDATE player SET allow_direct_messages = false WHERE id = :id', ['id' => $playerId]);
        }

        // No accepted conversation between the two unless the case adds one (a pending request does not count)
        if ($viewerId !== null && $viewerId !== $playerId) {
            $database->executeStatement(
                "UPDATE conversation SET status = 'pending' WHERE (initiator_id = :a AND recipient_id = :b) OR (initiator_id = :b AND recipient_id = :a)",
                ['a' => $viewerId, 'b' => $playerId],
            );
        }

        if (($setup['conversation'] ?? false) === true) {
            $database->executeStatement(
                "INSERT INTO conversation (id, initiator_id, recipient_id, status, created_at, responded_at, last_message_at) VALUES (:id, :a, :b, 'accepted', NOW(), NOW(), NOW())",
                ['id' => Uuid::uuid7()->toString(), 'a' => $playerId, 'b' => $viewerId],
            );
        }

        if (($setup['rankingOptOut'] ?? false) === true) {
            $database->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => $playerId]);
        }

        if (($setup['playerBlocksViewer'] ?? false) === true) {
            $database->executeStatement(
                "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
                ['id' => Uuid::uuid7()->toString(), 'blocker' => $playerId, 'blocked' => $viewerId],
            );
        }
    }

    /**
     * What every case must give - independent of the markup
     *
     * @param Setup $setup
     * @return array<string, mixed>
     */
    private static function expected(null|string $viewerId, string $playerId, array $setup): array
    {
        if ($viewerId === $playerId) {
            return ['isOwn' => true, 'favorite' => null, 'compare' => null, 'canMessage' => false];
        }

        if ($viewerId === null) {
            return ['isOwn' => false, 'favorite' => PlayerActions::SIGN_IN, 'compare' => PlayerActions::SIGN_IN, 'canMessage' => false, 'tierChip' => PlayerActions::TIER_LOCKED];
        }

        $expected = [
            'isOwn' => false,
            'isHidden' => false,
            'canMessage' => ($setup['dmsOff'] ?? false) === false || ($setup['conversation'] ?? false) === true,
        ];

        if (($setup['rankingOptOut'] ?? false) === true) {
            $expected['tierChip'] = null;
        }

        if ($playerId === PlayerFixture::PLAYER_PRIVATE) {
            // A private profile has no tier - not even the lock - also for a friend allowed to see it
            $expected['tierChip'] = null;
        }

        return $expected;
    }

    /**
     * @return array{favorite: null|string, compare: null|string, message: bool, tier: null|string, level: bool}
     */
    private static function cardActions(Crawler $card): array
    {
        $favorite = $card->filter('.players-card-actions a.players-card-favorite');
        $compareLink = $card->filter('.players-card-actions > a[data-nav$="/compare"]');

        return [
            'favorite' => match (true) {
                $favorite->count() === 0 => null,
                $favorite->attr('data-card-return-page') !== null => PlayerActions::SIGN_IN,
                str_contains((string) $favorite->attr('class'), 'is-on') => PlayerActions::FAVORITE_ON,
                default => PlayerActions::FAVORITE_OFF,
            },
            'compare' => match (true) {
                $card->filter('.players-card-actions form[action="/en/compare/add"]')->count() === 1 => PlayerActions::COMPARE_ADD,
                $compareLink->count() === 0 => null,
                str_starts_with((string) $compareLink->attr('href'), '/login') => PlayerActions::SIGN_IN,
                default => PlayerActions::COMPARE_OPEN,
            },
            'message' => $card->filter('.players-card-actions a.players-card-message')->count() === 1,
            'tier' => match (true) {
                $card->filter('.players-card-chip-tier')->count() === 1 => PlayerActions::TIER,
                $card->filter('.players-card-chip-locked')->count() === 1 => PlayerActions::TIER_LOCKED,
                $card->filter('.players-card-progress .players-card-chip:not(.players-card-chip-level)')->count() === 1 => PlayerActions::NO_TIER_YET,
                default => null,
            },
            'level' => $card->filter('.players-card-chip-level')->count() === 1,
        ];
    }
}
