<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\XpLaunchNotVerified;
use SpeedPuzzling\Web\Message\MarkXpLaunchReady;
use SpeedPuzzling\Web\MessageHandler\MarkXpLaunchReadyHandler;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Services\Xp\CachedXpLaunchReadiness;
use SpeedPuzzling\Web\Services\Xp\XpRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\XpSettingsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The launch switch flips only when the XP history verifies - never on a half-finished backfill.
 */
final class MarkXpLaunchReadyHandlerTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->database->executeStatement('UPDATE xp_settings SET launch_ready_at = NULL');
    }

    public function testACompleteHistoryFlipsTheSwitch(): void
    {
        $this->backfillEverybody();

        $verification = $this->handler()(new MarkXpLaunchReady());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertTrue($verification->passed());
        self::assertGreaterThan(0, $verification->playersWithSolves);
        self::assertNotNull($this->launchReadyAt());

        // The production switch reads the marker - and where the launch backfill's window starts (the deploy)
        $readiness = self::getContainer()->get(CachedXpLaunchReadiness::class);
        $readiness->forget();
        self::assertTrue($readiness->isReady());
        self::assertSame($this->launchReadyAt(), $readiness->readyAt()?->format('Y-m-d H:i:s'));
        self::assertSame(XpSettingsFixture::FULL_FORMULA_FROM, $readiness->stampedFrom()?->format('Y-m-d H:i:s'));
    }

    public function testAPlayerWithoutXpKeepsTheSwitchOff(): void
    {
        $this->backfillEverybody();
        $this->database->executeStatement('DELETE FROM xp_entry WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_REGULAR]);
        $this->database->executeStatement('UPDATE player SET xp_total = 0, level = 1 WHERE id = :player', ['player' => PlayerFixture::PLAYER_REGULAR]);

        $this->assertRefused();
    }

    public function testATotalThatIsNotTheLedgerSumKeepsTheSwitchOff(): void
    {
        $this->backfillEverybody();
        $this->database->executeStatement('UPDATE player SET xp_total = xp_total + 1 WHERE id = :player', ['player' => PlayerFixture::PLAYER_REGULAR]);

        $this->assertRefused();
    }

    public function testAchievementPointsThatAreNotTheAchievementsKeepTheSwitchOff(): void
    {
        $this->backfillEverybody();
        $this->database->executeStatement('UPDATE player SET achievement_points = achievement_points + 5 WHERE id = :player', ['player' => PlayerFixture::PLAYER_REGULAR]);

        $this->assertRefused();
    }

    private function assertRefused(): void
    {
        try {
            $this->handler()(new MarkXpLaunchReady());
            self::fail('The switch must stay off');
        } catch (XpLaunchNotVerified $exception) {
            self::assertNotSame([], $exception->verification->problems());
        }

        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertNull($this->launchReadyAt());

        $readiness = self::getContainer()->get(CachedXpLaunchReadiness::class);
        $readiness->forget();
        self::assertFalse($readiness->isReady());
        self::assertNull($readiness->stampedFrom(), 'No window while the switch is off');
    }

    private function backfillEverybody(): void
    {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        foreach ($container->get(GetAllPlayerIdsWithSolveTimes::class)->execute() as $playerId) {
            $container->get(XpRecomputer::class)->recomputeForPlayer($playerId);
            $container->get(BadgeEvaluator::class)->recalculateForPlayer($playerId);
            $entityManager->flush();
        }

        $entityManager->clear();
    }

    private function handler(): MarkXpLaunchReadyHandler
    {
        return self::getContainer()->get(MarkXpLaunchReadyHandler::class);
    }

    private function launchReadyAt(): null|string
    {
        $value = $this->database->fetchOne('SELECT launch_ready_at FROM xp_settings WHERE id = 1');

        return is_string($value) ? $value : null;
    }
}
