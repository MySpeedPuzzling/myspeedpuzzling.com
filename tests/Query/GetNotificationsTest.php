<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetNotificationsTest extends KernelTestCase
{
    private GetNotifications $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetNotifications::class);
    }

    public function testPuzzleSolvingNotificationContainsFirstAttemptFlag(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->firstAttempt === true && $notification->unboxed === false) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with firstAttempt=true and unboxed=false');
    }

    public function testPuzzleSolvingNotificationContainsUnboxedFlag(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->unboxed === true) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with unboxed=true');
    }

    public function testPuzzleSolvingNotificationWithoutFirstAttempt(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->firstAttempt === false) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with firstAttempt=false');
    }
}
