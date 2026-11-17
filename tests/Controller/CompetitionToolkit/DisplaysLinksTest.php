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
 * page and the online control room link to the event's displays - only while COMPETITION_TOOLKIT_MANAGE grants it.
 * Without the toolkit the tools row is byte for byte what it was: the link is the only difference.
 */
final class DisplaysLinksTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string TOOLKIT_FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
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

    public function testTheStopwatchPageLinksToTheDisplaysForWhomTheToolkitIsOn(): void
    {
        $withToolkit = $this->stopwatchTools(toolkit: true, player: self::ORGANISER);
        self::assertStringContainsString('<a href="/en/manage-displays/' . self::EVENT . '?return=' . self::STOPWATCH . '&amp;return_title=Manage%20Stopwatch" class="btn btn-outline-primary" data-displays-link="">', $withToolkit);

        $withoutToolkit = $this->stopwatchTools(toolkit: false, player: self::ORGANISER);
        self::assertStringNotContainsString('manage-displays', $withoutToolkit);
        self::assertSame($withoutToolkit, self::withoutLink($withToolkit, 'data-displays-link'), 'Without the toolkit the row is what it was');

        // Admins have the toolkit whatever the flag
        self::assertStringContainsString('data-displays-link', $this->stopwatchTools(toolkit: false, player: PlayerFixture::PLAYER_ADMIN));
    }

    public function testTheControlRoomLinksToTheDisplaysForWhomTheToolkitIsOn(): void
    {
        $withToolkit = $this->controlRoomTools(toolkit: true);
        self::assertStringContainsString('href="/en/manage-displays/' . self::ONLINE_EVENT . '?return=', $withToolkit);
        self::assertStringContainsString('data-online-tool="displays"', $withToolkit);

        $withoutToolkit = $this->controlRoomTools(toolkit: false);
        self::assertStringNotContainsString('manage-displays', $withoutToolkit);
        self::assertSame($withoutToolkit, self::withoutLink($withToolkit, 'data-online-tool="displays"'), 'Without the toolkit the row is what it was');
    }

    public function testTheDisplaysPageLeadsBackWhereTheOrganiserCameFrom(): void
    {
        $browser = $this->browser(toolkit: true, player: self::ORGANISER);

        $page = $browser->request('GET', '/en/manage-displays/' . self::EVENT . '?return=' . urlencode(self::STOPWATCH) . '&return_title=Manage%20Stopwatch');

        self::assertResponseIsSuccessful();
        self::assertSame(self::STOPWATCH, $page->filter('a:contains("Manage Stopwatch")')->attr('href'));
    }

    private function stopwatchTools(bool $toolkit, string $player): string
    {
        $browser = $this->browser($toolkit, $player);
        $page = $browser->request('GET', self::STOPWATCH);
        self::assertResponseIsSuccessful();

        return $page->filter('[data-stopwatch-tools]')->html();
    }

    private function controlRoomTools(bool $toolkit): string
    {
        $this->overrideFeatureFlagEnv(self::ONLINE_FLAG, true);
        $browser = $this->browser($toolkit, self::ORGANISER);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO competition_maintainer (competition_id, player_id) VALUES (:competition, :player) ON CONFLICT DO NOTHING',
            ['competition' => self::ONLINE_EVENT, 'player' => self::ORGANISER],
        );

        $page = $browser->request('GET', self::CONTROL_ROOM);
        self::assertResponseIsSuccessful();

        return $page->filter('[data-online-control-tools]')->html();
    }

    private function browser(bool $toolkit, string $player): KernelBrowser
    {
        self::ensureKernelShutdown();
        // Before the kernel boots - the flag is a container parameter
        $this->overrideFeatureFlagEnv(self::TOOLKIT_FLAG, $toolkit);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }

    /**
     * The tools row with the one link (and the indentation of its line) cut out.
     */
    private static function withoutLink(string $html, string $marker): string
    {
        $result = preg_replace('~<a [^>]*' . preg_quote($marker, '~') . '[^>]*>.*?</a>\n {12}~s', '', $html, 1, $count);
        self::assertSame(1, $count);
        self::assertIsString($result);

        return $result;
    }
}
