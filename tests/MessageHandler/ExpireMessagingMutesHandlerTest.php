<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\ExpireMessagingMutes;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * myspeedpuzzling:moderation:expire-mutes: a mute whose end has passed is lifted, a running one stays.
 */
final class ExpireMessagingMutesHandlerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testOnlyMutesThatHaveEndedAreLifted(): void
    {
        $players = self::getContainer()->get(PlayerRepository::class);
        $players->get(PlayerFixture::PLAYER_REGULAR)->muteMessaging(TestClock::now()->modify('-1 minute'));
        $players->get(PlayerFixture::PLAYER_PRIVATE)->muteMessaging(TestClock::now()->modify('+1 minute'));
        $players->get(PlayerFixture::PLAYER_ADMIN)->muteMessaging(TestClock::now()->modify('+7 days'));
        $players->get(PlayerFixture::PLAYER_WITH_STRIPE)->muteMessaging(TestClock::now()->modify('-7 days'));
        $this->entityManager()->flush();

        self::assertSame(2, $this->expireMutes());

        $this->entityManager()->clear();
        self::assertSame([false, null], $this->mute(PlayerFixture::PLAYER_REGULAR));
        self::assertSame([false, null], $this->mute(PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame([true, '2026-10-14 12:01:00'], $this->mute(PlayerFixture::PLAYER_PRIVATE));
        self::assertSame([true, '2026-10-21 12:00:00'], $this->mute(PlayerFixture::PLAYER_ADMIN));

        // Nothing left to lift
        self::assertSame(0, $this->expireMutes());
    }

    private function expireMutes(): int
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new ExpireMessagingMutes());
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);
        $expired = $handledStamp->getResult();
        self::assertIsInt($expired);

        return $expired;
    }

    /**
     * @return array{0: bool, 1: null|string}
     */
    private function mute(string $playerId): array
    {
        $player = self::getContainer()->get(PlayerRepository::class)->get($playerId);

        return [$player->messagingMuted, $player->messagingMutedUntil?->format('Y-m-d H:i:s')];
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
