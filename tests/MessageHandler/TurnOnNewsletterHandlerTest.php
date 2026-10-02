<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Message\TurnOnNewsletter;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class TurnOnNewsletterHandlerTest extends KernelTestCase
{
    public function testSwitchedOffNewsletterIsTurnedOnAndPushedToListmonk(): void
    {
        self::bootKernel();
        $this->switchNewsletterOff(PlayerFixture::PLAYER_REGULAR);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new TurnOnNewsletter(PlayerFixture::PLAYER_REGULAR));

        self::assertTrue($this->newsletterEnabled(PlayerFixture::PLAYER_REGULAR));
        self::assertCount(1, $this->listmonkPushes());
    }

    public function testAlreadySubscribedPlayerIsLeftAlone(): void
    {
        self::bootKernel();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new TurnOnNewsletter(PlayerFixture::PLAYER_REGULAR));

        self::assertTrue($this->newsletterEnabled(PlayerFixture::PLAYER_REGULAR));
        self::assertCount(0, $this->listmonkPushes());
    }

    private function switchNewsletterOff(string $playerId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET newsletter_enabled = false WHERE id = :id', ['id' => $playerId]);
    }

    private function newsletterEnabled(string $playerId): bool
    {
        return (bool) self::getContainer()->get(Connection::class)->fetchOne('SELECT newsletter_enabled FROM player WHERE id = :id', ['id' => $playerId]);
    }

    /**
     * @return list<Envelope>
     */
    private function listmonkPushes(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return array_values(array_filter(
            [...$transport->getSent()],
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof PushNewsletterSubscriberToListmonk,
        ));
    }
}
