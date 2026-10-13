<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The scheduled start of the round stopwatch is online timing's prototype until the test cups are done
 * (docs/features/feature_flags.md "ONLINE_ROUNDS_FOR_ORGANISERS"): "Start in 10 s", "Start at a set time" and "Cancel
 * the start" only for ONLINE_ROUNDS_MANAGE - admins, organisers while the flag is on. Start / Stop / Reset stay for every
 * organiser.
 */
final class ScheduledStartFeatureFlagTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string ROUND = OfficialResultsFixture::ROUND_GROUP_A;
    // The Results Cup's organiser - not an admin
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string MANAGE = '/en/manage-round-stopwatch/' . self::ROUND;
    private const string SCHEDULE = '/en/round-stopwatch/' . self::ROUND . '/schedule';
    private const string CANCEL = '/en/round-stopwatch/' . self::ROUND . '/cancel';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAnOrganiserWithoutTheFlagSeesNoSchedulingActions(): void
    {
        $browser = $this->browser(flag: false, player: self::ORGANISER);

        $page = $browser->request('GET', self::MANAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, self::scheduling($page));
        // Start, Stop and Reset stay
        self::assertCount(1, $page->filter('form[action="/en/start-round-stopwatch/' . self::ROUND . '"]'));
        self::assertCount(1, $page->filter('form[action="/en/stop-round-stopwatch/' . self::ROUND . '"]'));
        self::assertCount(1, $page->filter('form[action="/en/reset-round-stopwatch/' . self::ROUND . '"]'));
    }

    public function testAnOrganiserWithoutTheFlagIsRefusedOnBothRoutes(): void
    {
        $browser = $this->browser(flag: false, player: self::ORGANISER);

        foreach ([self::SCHEDULE, self::CANCEL] as $url) {
            $browser->request('POST', $url, ['_token' => 'csrf-token', 'countdown' => '10'], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']);

            self::assertResponseStatusCodeSame(403);
            self::assertSame('{"error":"scheduling_unavailable"}', $browser->getResponse()->getContent());
        }

        // The form of a page left open before - said, nothing changes
        $browser->request('POST', self::SCHEDULE, ['_token' => 'csrf-token', 'countdown' => '10'], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects(self::MANAGE);
        self::assertNull($this->stopwatchStatus());
        $browser->followRedirect();
        self::assertSelectorTextContains('.alert', 'not available for this event yet');
    }

    public function testTheFlagOpensTheSchedulingToTheOrganiser(): void
    {
        $browser = $this->browser(flag: true, player: self::ORGANISER);

        $page = $browser->request('GET', self::MANAGE);
        self::assertCount(3, self::scheduling($page), '"Start in 10 s", "Start at a set time", "Cancel the start"');

        $browser->request('POST', self::SCHEDULE, ['_token' => 'csrf-token', 'countdown' => '10'], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects(self::MANAGE);
        self::assertSame('scheduled', $this->stopwatchStatus());

        $browser->request('POST', self::CANCEL, ['_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects(self::MANAGE);
        self::assertNull($this->stopwatchStatus());
    }

    public function testAdminsScheduleWhileTheFlagIsOff(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::MANAGE);
        self::assertCount(3, self::scheduling($page));

        $browser->request('POST', self::SCHEDULE, ['_token' => 'csrf-token', 'countdown' => '10'], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame('scheduled', $this->stopwatchStatus());
    }

    private function browser(bool $flag, string $player): KernelBrowser
    {
        // Before the kernel boots - the flag is a container parameter
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }

    /**
     * The scheduling forms on the control page - "Start in 10 s" and "Start at a set time" post to schedule, "Cancel the
     * start" to cancel.
     */
    private static function scheduling(Crawler $page): Crawler
    {
        return $page->filter('form[action="' . self::SCHEDULE . '"], form[action="' . self::CANCEL . '"]');
    }

    private function stopwatchStatus(): mixed
    {
        $status = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT stopwatch_status FROM competition_round WHERE id = :id',
            ['id' => self::ROUND],
        );

        return $status === false ? null : $status;
    }
}
