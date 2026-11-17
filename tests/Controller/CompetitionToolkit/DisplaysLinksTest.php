<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Displays" where organisers start rounds (competition toolkit D8, D-displays-overlays.md §8.1): the stopwatch control
 * page and the online control room link to the event's displays (COMPETITION_TOOLKIT_MANAGE = every organiser).
 */
final class DisplaysLinksTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string ONLINE_FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string STOPWATCH = '/en/manage-round-stopwatch/' . OfficialResultsFixture::ROUND_GROUP_A;

    private const string ONLINE_EVENT = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string CONTROL_ROOM = '/en/online-rounds/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/control';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheStopwatchPageLinksToTheDisplays(): void
    {
        $tools = $this->stopwatchTools(player: self::ORGANISER);
        self::assertStringContainsString('<a href="/en/manage-displays/' . self::EVENT . '?return=' . self::STOPWATCH . '&amp;return_title=Manage%20Stopwatch" class="btn btn-outline-primary" data-displays-link="">', $tools);

        self::assertStringContainsString('data-displays-link', $this->stopwatchTools(player: PlayerFixture::PLAYER_ADMIN));
    }

    public function testTheControlRoomLinksToTheDisplays(): void
    {
        $tools = $this->controlRoomTools();
        self::assertStringContainsString('href="/en/manage-displays/' . self::ONLINE_EVENT . '?return=', $tools);
        self::assertStringContainsString('data-online-tool="displays"', $tools);
    }

    public function testTheDisplaysPageLeadsBackWhereTheOrganiserCameFrom(): void
    {
        $browser = $this->browser(self::ORGANISER);

        $page = $browser->request('GET', '/en/manage-displays/' . self::EVENT . '?return=' . urlencode(self::STOPWATCH) . '&return_title=Manage%20Stopwatch');

        self::assertResponseIsSuccessful();
        self::assertSame(self::STOPWATCH, $page->filter('a:contains("Manage Stopwatch")')->attr('href'));
    }

    private function stopwatchTools(string $player): string
    {
        $browser = $this->browser($player);
        $page = $browser->request('GET', self::STOPWATCH);
        self::assertResponseIsSuccessful();

        return $page->filter('[data-stopwatch-tools]')->html();
    }

    private function controlRoomTools(): string
    {
        $this->overrideFeatureFlagEnv(self::ONLINE_FLAG, true);
        $browser = $this->browser(self::ORGANISER);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO competition_maintainer (competition_id, player_id) VALUES (:competition, :player) ON CONFLICT DO NOTHING',
            ['competition' => self::ONLINE_EVENT, 'player' => self::ORGANISER],
        );

        $page = $browser->request('GET', self::CONTROL_ROOM);
        self::assertResponseIsSuccessful();

        return $page->filter('[data-online-control-tools]')->html();
    }

    private function browser(string $player): KernelBrowser
    {
        self::ensureKernelShutdown();

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
