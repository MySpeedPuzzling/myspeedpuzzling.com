<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The organization page and the organizations directory cost a fixed number of statements
 * (docs/features/organizations/implementation-plan.md 4.5), pinned exactly - a higher number is a bug to explain, not a
 * new number. The second request is counted (the first warms the caches). Signed in adds what every signed-in request
 * loads (account, profile, unread conversations, unread notifications).
 *
 * Page: the organization, its occurrences in one statement, its series, the going counts of the coming ones; signed in
 * + the viewer's going/follow rows + the permissions statement (the voters share it). Directory: the organizations with
 * their counts, their occurrences.
 */
final class OrganizationPagesQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string RIVERBEND = '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;
    private const string DIRECTORY = '/en/organizations';

    /**
     * @return iterable<string, array{string, null|string, int}>
     */
    public static function providePages(): iterable
    {
        yield 'organization page, guest' => [self::RIVERBEND, null, 4];
        yield 'organization page, player' => [self::RIVERBEND, PlayerFixture::PLAYER_REGULAR, 10];
        yield 'organization page, team' => [self::RIVERBEND, PlayerFixture::PLAYER_WITH_STRIPE, 10];
        yield 'organization page, maintainer' => [self::RIVERBEND, PlayerFixture::PLAYER_WITH_FAVORITES, 10];
        // + the XP ring in the header (GetXpProfile) - admins only while the xp-system flag is on
        yield 'organization page, admin' => [self::RIVERBEND, PlayerFixture::PLAYER_ADMIN, 11];
        yield 'directory, guest' => [self::DIRECTORY, null, 2];
        yield 'directory, player' => [self::DIRECTORY, PlayerFixture::PLAYER_REGULAR, 6];
    }

    #[DataProvider('providePages')]
    public function testThePageCost(string $url, null|string $playerId, int $statements): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        self::assertSame($statements, $this->measure($browser, $url), ($playerId ?? 'guest') . ' ' . $url);
    }

    public function testTenMoreSeriesWithEditionsAndOneTimeEventsAddNoStatement(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $pageBefore = $this->measure($browser, self::RIVERBEND);
        $directoryBefore = $this->measure($browser, self::DIRECTORY);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);

        for ($i = 1; $i <= 10; $i++) {
            $seriesId = Uuid::uuid7()->toString();
            $connection->executeStatement(
                "INSERT INTO competition_series (id, name, slug, is_online, created_at, approved_at, organization_id)
                 VALUES (:id, :name, :slug, false, NOW(), NOW(), :organization)",
                ['id' => $seriesId, 'name' => 'Budget series ' . $i, 'slug' => 'budget-series-' . $i, 'organization' => OrganizationFixture::ORGANIZATION_RIVERBEND],
            );

            foreach ([20, 50, -40] as $days) {
                $connection->executeStatement(
                    "INSERT INTO competition (id, name, slug, series_id, is_online, created_at, date_from, date_to)
                     VALUES (:id, :name, :slug, :series, false, NOW(), CURRENT_DATE + make_interval(days => :days), CURRENT_DATE + make_interval(days => :days))",
                    ['id' => Uuid::uuid7()->toString(), 'name' => 'Budget edition ' . $days, 'slug' => 'budget-edition-' . ($days + 100), 'series' => $seriesId, 'days' => $days + $i],
                );
            }

            $connection->executeStatement(
                "INSERT INTO competition (id, name, slug, is_online, created_at, approved_at, organization_id, date_from, date_to)
                 VALUES (:id, :name, :slug, true, NOW(), NOW(), :organization, CURRENT_DATE + make_interval(days => :days), CURRENT_DATE + make_interval(days => :days))",
                ['id' => Uuid::uuid7()->toString(), 'name' => 'Budget event ' . $i, 'slug' => 'budget-event-' . $i, 'organization' => OrganizationFixture::ORGANIZATION_RIVERBEND, 'days' => 30 + $i],
            );
        }

        self::assertSame($pageBefore, $this->measure($browser, self::RIVERBEND), 'ten more series, editions and events must not add statements to the page');
        self::assertGreaterThanOrEqual(12, $browser->getCrawler()->filter('[data-org-series]')->count(), 'every series has its card');
        self::assertGreaterThanOrEqual(11, $browser->getCrawler()->filter('[data-org-event]')->count(), 'every coming one-time event has its card');
        self::assertSame($directoryBefore, $this->measure($browser, self::DIRECTORY), 'nor to the directory');
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
