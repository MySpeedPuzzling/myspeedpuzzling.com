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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "You organize" costs a fixed number of statements (docs/features/organizations/implementation-plan.md 4.5): what
 * every signed-in request loads (account, profile, unread conversations and notifications), the viewer statement, one
 * statement per kind of item it lists (organizations, events and editions, series) and the permissions statement the
 * voters of every item's actions share (GetCompetitionPermissions, memoised). Nothing per item: Unpublish is offered
 * without asking UnpublishBlockers, the badges and counts ride on the item statements.
 */
final class OrganizedEventsQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    // Account, profile, unread conversations, unread notifications
    private const int SIGNED_IN_OVERHEAD = 4;
    // The viewer statement + the permissions statement
    private const int PAGE = 2;

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideOrganisers(): iterable
    {
        // Organizations, one-time events and series
        yield 'organizations and the rest' => [PlayerFixture::PLAYER_WITH_STRIPE, self::SIGNED_IN_OVERHEAD + self::PAGE + 3];
        // Organizations (with their series and one-time event) only
        yield 'a team member' => [PlayerFixture::PLAYER_WITH_FAVORITES, self::SIGNED_IN_OVERHEAD + self::PAGE + 3];
        // No organization, no series: its events only
        yield 'no organization' => [PlayerFixture::PLAYER_REGULAR, self::SIGNED_IN_OVERHEAD + self::PAGE + 1];
    }

    #[DataProvider('provideOrganisers')]
    public function testThePageCost(string $playerId, int $budget): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        $browser->request('GET', '/en/you-organize');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/you-organize');

        self::assertResponseIsSuccessful();
        self::assertSame($budget, $this->queryCount($browser), implode("\n", $this->executedSql($browser)));
    }

    public function testMoreItemsAddNoStatement(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/you-organize');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/you-organize');
        $before = $this->queryCount($browser);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        // Five more: two series and two one-time events under Riverbend, one draft event of the player's own
        for ($i = 1; $i <= 2; $i++) {
            $seriesId = Uuid::uuid7()->toString();
            $connection->insert('competition_series', [
                'id' => $seriesId,
                'name' => 'Budget Series ' . $i,
                'slug' => 'budget-series-' . $i,
                'is_online' => 'true',
                'created_at' => '2026-01-01 10:00:00',
                'organization_id' => OrganizationFixture::ORGANIZATION_RIVERBEND,
                'is_draft' => 'false',
            ]);
            $connection->insert('competition', $this->competition('Budget Event ' . $i, OrganizationFixture::ORGANIZATION_RIVERBEND, false));
        }

        $connection->insert('competition', $this->competition('Budget Draft', null, true) + ['added_by_player_id' => PlayerFixture::PLAYER_WITH_STRIPE]);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/you-organize');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Budget Draft', $crawler->text());
        self::assertSame($before, $this->queryCount($browser), implode("\n", $this->executedSql($browser)));
    }

    /**
     * @return array<string, string|null>
     */
    private function competition(string $name, null|string $organizationId, bool $isDraft): array
    {
        return [
            'id' => Uuid::uuid7()->toString(),
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'is_online' => 'true',
            'date_from' => '2027-03-01 00:00:00',
            'date_to' => '2027-03-01 00:00:00',
            'organization_id' => $organizationId,
            'is_draft' => $isDraft ? 'true' : 'false',
            'approved_at' => '2026-01-01 10:00:00',
        ];
    }
}
