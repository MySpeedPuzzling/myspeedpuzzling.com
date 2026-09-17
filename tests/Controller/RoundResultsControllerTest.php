<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoundResultsControllerTest extends WebTestCase
{
    private const string QUALIFICATION_URL = '/en/events/wjpc-2024/results/qualification-round';

    public function testUpcomingRoundSaysWhenItStarts(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::QUALIFICATION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'This round starts');
        $this->assertSelectorNotExists('[data-round-result-status]');
    }

    public function testStartedRoundRanksItsResults(): void
    {
        $browser = self::createClient();
        $this->startQualificationRound($browser);

        $crawler = $browser->request('GET', self::QUALIFICATION_URL);

        $this->assertResponseIsSuccessful();
        // Three fixture results, the private player's is hidden from anonymous visitors
        self::assertCount(2, $crawler->filter('[data-round-result-status="finished"]'));
        $this->assertSelectorTextContains('title', 'Qualification Round');
    }

    public function testLocalizedUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/eventy/wjpc-2024/vysledky/qualification-round');

        $this->assertResponseIsSuccessful();
    }

    public function testUnknownRoundIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/wjpc-2024/results/no-such-round');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRoundOfACompetitionThatIsNotPublicIsNotFound(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, category, slug) VALUES (:id, :competition, 'Hidden', 60, NOW() - INTERVAL '1 day', 'solo', 'hidden')",
            ['id' => Uuid::uuid7()->toString(), 'competition' => CompetitionFixture::COMPETITION_UNAPPROVED],
        );

        $browser->request('GET', '/en/events/unapproved-puzzle-event/results/hidden');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRoundNavigationAndOfficialResultsLink(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET results_link = 'https://example.com/wjpc/qualification' WHERE id = :id",
            ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        );

        $browser->request('GET', self::QUALIFICATION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/en/events/wjpc-2024/results/final-round"]');
        $this->assertSelectorExists('a[href="https://example.com/wjpc/qualification?utm_source=myspeedpuzzling"]');
    }

    public function testAddMyTimeOnlyForSignedInPlayersOnceTheRoundStarted(): void
    {
        $browser = self::createClient();
        $this->startQualificationRound($browser);
        $addTimeLink = sprintf('a[href$="/%s?competition=%s"]', PuzzleFixture::PUZZLE_500_01, CompetitionFixture::COMPETITION_WJPC_2024);

        $browser->request('GET', self::QUALIFICATION_URL);
        $this->assertSelectorNotExists($addTimeLink);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::QUALIFICATION_URL);
        $this->assertSelectorExists($addTimeLink);
    }

    public function testEditionRoundResults(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round');

        $this->assertResponseIsSuccessful();
    }

    public function testEditionReachedThroughTheEventRouteRedirects(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/ejj-68-february-2026/results/main-round');

        $this->assertResponseRedirects('/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round', 301);
    }

    private function startQualificationRound(KernelBrowser $browser): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET starts_at = NOW() - INTERVAL '2 days' WHERE id = :id",
            ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        );
    }
}
