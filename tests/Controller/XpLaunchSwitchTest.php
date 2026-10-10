<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The XP launch switch (`xp_settings.launch_ready_at`, docs/features/feature_flags.md): until the backfill verified
 * the history, XP / levels / achievements and the launch reveal are for admins only - nobody else may meet
 * "Level 1, 1 XP". The tests otherwise run launched (FixedXpLaunchReadiness).
 */
final class XpLaunchSwitchTest extends WebTestCase
{
    public function testBeforeTheLaunchAPlayerSeesNoXpPagesNoRevealAndNoModal(): void
    {
        $browser = self::createClient();
        $this->notLaunchedYet();
        $this->launchModalNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/achievements');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/my/xp-reveal');
        self::assertResponseRedirects();

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#announcement-modal[data-autoshow-modal-name-value="xp_launch_reveal"]'));
        self::assertSame(0, $this->launchModalImpressions($browser, PlayerFixture::PLAYER_REGULAR), 'Nothing used up - the modal comes after the launch');
    }

    public function testBeforeTheLaunchAnAdminSeesEverythingWithoutUsingTheirModalUp(): void
    {
        $browser = self::createClient();
        $this->notLaunchedYet();
        $this->launchModalNotSeenYet($browser, PlayerFixture::PLAYER_ADMIN);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/achievements');
        self::assertResponseIsSuccessful();

        $browser->request('GET', '/en/my/xp-reveal');
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->launchModalImpressions($browser, PlayerFixture::PLAYER_ADMIN));
    }

    public function testAfterTheLaunchEverybodySeesItAndTheModalStarts(): void
    {
        $browser = self::createClient();
        $this->launchModalNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/achievements');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#announcement-modal[data-autoshow-modal-name-value="xp_launch_reveal"]'));
    }

    private function notLaunchedYet(): void
    {
        FixedXpLaunchReadiness::$ready = false;
    }

    private function launchModalNotSeenYet(KernelBrowser $browser, string $playerId): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "DELETE FROM player_modal_impression WHERE player_id = :player AND modal = 'xp_launch_reveal'",
            ['player' => $playerId],
        );
    }

    private function launchModalImpressions(KernelBrowser $browser, string $playerId): int
    {
        $count = $browser->getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM player_modal_impression WHERE player_id = :player AND modal = 'xp_launch_reveal'",
            ['player' => $playerId],
        );

        return is_numeric($count) ? (int) $count : -1;
    }
}
