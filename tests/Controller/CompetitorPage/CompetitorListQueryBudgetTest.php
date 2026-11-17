<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitorPage;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The competitor list costs a fixed number of statements, whatever the event's size (design-v2 competitors-splits.md
 * §2.1 "Performance" - a QR crowd of 300-800 phones at the venue): the competition (1) and its people and pairs/teams
 * with their members (1). Signed in adds what every signed-in request loads (account, profile with the hidden set,
 * unread counts). Pinned exactly; the second request is counted (the first warms the caches).
 */
final class CompetitorListQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string URL = '/en/competitor/' . F::COMPETITION_RESULTS_CUP;
    private const int GUEST = 2;
    private const int SIGNED_IN = 6;

    public function testAGuestPaysTwoStatements(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        self::assertSame(self::GUEST, $this->measure($browser), implode("\n", $this->executedSql($browser)));
    }

    public function testASignedInPlayerPaysOnlyTheLayoutsStatementsMore(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertSame(self::SIGNED_IN, $this->measure($browser), implode("\n", $this->executedSql($browser)));
    }

    public function testFourHundredPeopleAndAHundredTwentyPairsAddNoStatement(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source)
             SELECT CAST('019a0c10-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 'Budget Person ' || n, 'cz', :competition, 'imported'
             FROM generate_series(1, 400) AS n",
            ['competition' => F::COMPETITION_RESULTS_CUP],
        );
        $database->executeStatement(
            "INSERT INTO competition_team (id, round_id, name)
             SELECT CAST('019a0c11-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), :round, 'Budget Pair ' || n
             FROM generate_series(1, 120) AS n",
            ['round' => F::ROUND_PAIRS],
        );
        $database->executeStatement(
            "INSERT INTO competition_participant_round (id, participant_id, round_id, team_id)
             SELECT CAST('019a0c12-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid),
                 CAST('019a0c10-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid),
                 :round,
                 CAST('019a0c11-0000-7000-8000-' || lpad(((n + 1) / 2)::text, 12, '0') AS uuid)
             FROM generate_series(1, 240) AS n",
            ['round' => F::ROUND_PAIRS],
        );

        self::assertSame(self::GUEST, $this->measure($browser), implode("\n", $this->executedSql($browser)));
        $crawler = $browser->getCrawler();
        self::assertCount(409, $crawler->filter('[data-competitor-list-row="p"]'));
        self::assertCount(124, $crawler->filter('[data-competitor-list-row="g"]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(self::SIGNED_IN, $this->measure($browser), implode("\n", $this->executedSql($browser)));
    }

    private function turnOn(KernelBrowser $browser): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET competitor_pages = true WHERE id = :id',
            ['id' => F::COMPETITION_RESULTS_CUP],
        );
    }

    private function measure(KernelBrowser $browser): int
    {
        $browser->request('GET', self::URL);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
