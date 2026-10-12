<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The viewers of the navigation budget tests (docs/features/navigation/delivery/baseline.md, P0-02): one fixture per
 * role, shared by ChromeQueryBudgetTest, HubQueryBudgetTest and PlayerProfileQueryBudgetTest. Signing in first asserts
 * what the fixture is in the database, so a budget row means what its name says even after the fixtures change.
 *
 * No fixture player is a moderator: the moderator is PLAYER_WITH_FAVORITES with the role granted in the test (DAMA
 * rolls the grant back), as Admin/ModeratorAccessTest does.
 */
readonly final class NavigationViewer
{
    public const string GUEST = 'guest';
    // A signed-in non-member without any role: maintains and created nothing, follows nobody (private profile)
    public const string PLAYER = 'player';
    // A non-member who follows players (their favorites feed the Hub); also maintains and created events
    public const string PLAYER_WITH_FAVORITES = 'player with favorites';
    // A member without a moderation role; also created organizations
    public const string MEMBER = 'member';
    // A non-member who maintains competitions (the "maintainer" of EventsPageQueryBudgetTest)
    public const string ORGANISER = 'organiser';
    // PLAYER_WITH_FAVORITES with the moderator role granted: the admin key with the puzzle review queues
    public const string MODERATOR = 'moderator';
    public const string ADMIN = 'admin';

    private const array FIXTURES = [
        self::PLAYER => PlayerFixture::PLAYER_PRIVATE,
        self::PLAYER_WITH_FAVORITES => PlayerFixture::PLAYER_WITH_FAVORITES,
        self::MEMBER => PlayerFixture::PLAYER_WITH_STRIPE,
        self::ORGANISER => PlayerFixture::PLAYER_REGULAR,
        self::MODERATOR => PlayerFixture::PLAYER_WITH_FAVORITES,
        self::ADMIN => PlayerFixture::PLAYER_ADMIN,
    ];

    /**
     * What each fixture is, as read from the database right before signing in.
     *
     * @var array<string, array{member: bool, admin: bool, moderator: bool, maintains_events: bool, created_events: bool, follows_players: bool}>
     */
    private const array ROLE_FACTS = [
        self::PLAYER => ['member' => false, 'admin' => false, 'moderator' => false, 'maintains_events' => false, 'created_events' => false, 'follows_players' => false],
        self::PLAYER_WITH_FAVORITES => ['member' => false, 'admin' => false, 'moderator' => false, 'maintains_events' => true, 'created_events' => true, 'follows_players' => true],
        self::MEMBER => ['member' => true, 'admin' => false, 'moderator' => false, 'maintains_events' => false, 'created_events' => true, 'follows_players' => false],
        self::ORGANISER => ['member' => false, 'admin' => false, 'moderator' => false, 'maintains_events' => true, 'created_events' => true, 'follows_players' => false],
        self::MODERATOR => ['member' => false, 'admin' => false, 'moderator' => true, 'maintains_events' => true, 'created_events' => true, 'follows_players' => true],
        self::ADMIN => ['member' => true, 'admin' => true, 'moderator' => false, 'maintains_events' => true, 'created_events' => true, 'follows_players' => false],
    ];

    public static function playerId(string $role): string
    {
        Assert::assertArrayHasKey($role, self::FIXTURES, sprintf('"%s" is not a signed-in navigation viewer', $role));

        return self::FIXTURES[$role];
    }

    /**
     * Signs the browser in as the role (a guest: clears the cookies) after asserting the fixture is that role.
     *
     * @return null|string the viewer's player id, null for a guest
     */
    public static function signIn(KernelBrowser $browser, string $role): null|string
    {
        if ($role === self::GUEST) {
            $browser->getCookieJar()->clear();

            return null;
        }

        $playerId = self::playerId($role);

        if ($role === self::MODERATOR) {
            // KernelBrowser::getContainer() is the test container in the test environment
            $bus = $browser->getContainer()->get(MessageBusInterface::class); // @phpstan-ignore symfonyContainer.privateService
            $bus->dispatch(new GrantModeratorRole($playerId));
        }

        Assert::assertSame(self::ROLE_FACTS[$role], self::roleFacts($browser, $playerId), sprintf('The "%s" fixture is no longer what the budget row is named after', $role));

        TestingLogin::asPlayer($browser, $playerId);

        return $playerId;
    }

    /**
     * @return array{member: bool, admin: bool, moderator: bool, maintains_events: bool, created_events: bool, follows_players: bool}
     */
    private static function roleFacts(KernelBrowser $browser, string $playerId): array
    {
        $container = $browser->getContainer();

        $connection = $container->get(Connection::class); // @phpstan-ignore symfonyContainer.privateService

        $row = $connection->fetchAssociative(
            <<<'SQL'
            SELECT
                p.is_admin AS admin,
                p.moderator_since IS NOT NULL AS moderator,
                EXISTS (SELECT 1 FROM competition_maintainer m WHERE m.player_id = p.id)
                    OR EXISTS (SELECT 1 FROM competition_series_maintainer m WHERE m.player_id = p.id)
                    OR EXISTS (SELECT 1 FROM organization_maintainer m WHERE m.player_id = p.id) AS maintains_events,
                EXISTS (SELECT 1 FROM competition c WHERE c.added_by_player_id = p.id)
                    OR EXISTS (SELECT 1 FROM competition_series s WHERE s.added_by_player_id = p.id)
                    OR EXISTS (SELECT 1 FROM organization o WHERE o.added_by_player_id = p.id) AS created_events
            FROM player p
            WHERE p.id = :id
            SQL,
            ['id' => $playerId],
        );
        Assert::assertIsArray($row, 'The fixture player ' . $playerId . ' does not exist');

        $profile = $container->get(GetPlayerProfile::class)->byId($playerId);

        return [
            'member' => $profile->activeMembership,
            'admin' => (bool) $row['admin'],
            'moderator' => (bool) $row['moderator'],
            'maintains_events' => (bool) $row['maintains_events'],
            'created_events' => (bool) $row['created_events'],
            'follows_players' => $profile->favoritePlayers !== [],
        ];
    }
}
