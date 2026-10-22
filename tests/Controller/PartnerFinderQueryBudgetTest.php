<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The partner finder page costs a fixed number of statements (docs/features/partner-finder.md "Performance budgets"),
 * pinned exactly - a higher number is a bug to explain, not a new number. The second request is counted (the first warms
 * the caches). Guest: the event (by its slug) and the teaser statement. Signed in: the event, the listings statement - and
 * what every signed-in request loads (account, profile, unread conversations, unread notifications).
 */
final class PartnerFinderQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FINDER = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG . '/partners';

    /**
     * @return iterable<string, array{null|string, int}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null, 2];
        yield 'player with a listing' => [PlayerFixture::PLAYER_REGULAR, 6];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE, 6];
    }

    #[DataProvider('provideViewers')]
    public function testThePageCost(null|string $playerId, int $statements): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        self::assertSame($statements, $this->measure($browser), $playerId ?? 'guest');

        $finderStatements = array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'FROM partner_listing'),
        );
        self::assertCount(1, $finderStatements, 'one statement reads the listings');
    }

    public function testMoreListingsAddNoStatement(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $before = $this->measure($browser);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);

        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE] as $playerId) {
            $connection->executeStatement(
                "INSERT INTO partner_listing (id, competition_id, player_id, group_size, looking_for, co_member_ids, strengths, custom_strengths, languages, created_at, updated_at)
                 VALUES (gen_random_uuid(), :competition, :player, 1, '[\"team\"]', '[]', '[\"sky\"]', '[\"Wasgij\"]', '[\"en\"]', NOW(), NOW())",
                ['competition' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, 'player' => $playerId],
            );
        }

        self::assertSame($before, $this->measure($browser));
    }

    private function measure(KernelBrowser $browser): int
    {
        $browser->request('GET', self::FINDER);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::FINDER);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
