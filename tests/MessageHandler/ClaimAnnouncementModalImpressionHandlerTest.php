<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\ClaimAnnouncementModalImpression;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\AnnouncementModal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ClaimAnnouncementModalImpressionHandlerTest extends KernelTestCase
{
    public function testOnlyTheFirstClaimPerPlayerWins(): void
    {
        self::assertTrue($this->claim(PlayerFixture::PLAYER_REGULAR));
        self::assertFalse($this->claim(PlayerFixture::PLAYER_REGULAR));
        self::assertFalse($this->claim(PlayerFixture::PLAYER_REGULAR));

        self::assertTrue($this->claim(PlayerFixture::PLAYER_ADMIN), 'Somebody else is somebody else');
    }

    private function claim(string $playerId): bool
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new ClaimAnnouncementModalImpression($playerId, AnnouncementModal::FreeTrialOffer),
        );

        return $envelope->last(HandledStamp::class)?->getResult() === true;
    }
}
