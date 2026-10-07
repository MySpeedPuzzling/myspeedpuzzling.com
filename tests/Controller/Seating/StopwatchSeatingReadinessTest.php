<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Seating;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The seating step on the round's stopwatch control page: "Tables: x / y assigned - recommended before the round
 * starts" for an in-person round about to start (SeatingReadiness, docs/features/competitions-management/seating.md).
 */
final class StopwatchSeatingReadinessTest extends WebTestCase
{
    private const string PAGE = '/en/manage-round-stopwatch/' . OfficialResultsFixture::ROUND_GROUP_A;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testAnUpcomingInPersonRoundRecommendsSeating(): void
    {
        $this->startsIn('+3 hours');

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $readiness = $crawler->filter('[data-seating-readiness]');
        self::assertCount(1, $readiness);
        self::assertStringContainsString('Tables: 5 / 6 assigned', $readiness->text());
        self::assertStringContainsString('recommended before the round starts', $readiness->text());
        self::assertSame('/en/round-seating/' . OfficialResultsFixture::ROUND_GROUP_A, $readiness->filter('a')->attr('href'));
    }

    public function testEverybodySeatedIsSaidQuietly(): void
    {
        $this->startsIn('+3 hours');
        $this->database->executeStatement('UPDATE competition_participant_round SET table_number = 6 WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_FILIP]);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertStringContainsString('Every entry has a table.', $crawler->filter('[data-seating-readiness]')->text());
    }

    public function testNothingWhenTheRoundDoesWithoutTableNumbersIsOnlineStartedOrOver(): void
    {
        // Over (the fixture round was 10 days ago) - the page is as it always was
        $crawler = $this->browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-seating-readiness]'));

        $this->startsIn('+3 hours');

        $this->database->executeStatement("UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = NOW() WHERE id = :id", ['id' => OfficialResultsFixture::ROUND_GROUP_A]);
        self::assertCount(0, $this->browser->request('GET', self::PAGE)->filter('[data-seating-readiness]'));
        $this->database->executeStatement('UPDATE competition_round SET stopwatch_status = NULL, stopwatch_started_at = NULL WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]);

        $this->database->executeStatement('UPDATE competition_round SET table_numbers_off = true WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]);
        self::assertCount(0, $this->browser->request('GET', self::PAGE)->filter('[data-seating-readiness]'));
        $this->database->executeStatement('UPDATE competition_round SET table_numbers_off = false WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]);

        $this->database->executeStatement('UPDATE competition SET is_online = true WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        self::assertCount(0, $this->browser->request('GET', self::PAGE)->filter('[data-seating-readiness]'));
    }

    public function testARoundWithoutEntrantsKeepsItsPage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        // An upcoming round of an event that never assigned anybody to it
        $crawler = $this->browser->request('GET', '/en/manage-round-stopwatch/' . CompetitionRoundFixture::ROUND_CZECH_FINAL);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-seating-readiness]'));
        self::assertSelectorTextContains('h1', 'Manage Stopwatch');
    }

    private function startsIn(string $modifier): void
    {
        $startsAt = self::getContainer()->get(ClockInterface::class)->now()->modify($modifier);

        $this->database->executeStatement('UPDATE competition_round SET starts_at = :startsAt WHERE id = :id', [
            'startsAt' => $startsAt->format('Y-m-d H:i:s'),
            'id' => OfficialResultsFixture::ROUND_GROUP_A,
        ]);
    }
}
