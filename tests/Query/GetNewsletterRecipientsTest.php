<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetNewsletterRecipients;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Only verified addresses reach Listmonk: registration has no double opt-in, the verification link proves the address.
 */
final class GetNewsletterRecipientsTest extends KernelTestCase
{
    private Connection $database;
    private GetNewsletterRecipients $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->query = self::getContainer()->get(GetNewsletterRecipients::class);
    }

    public function testVerifiedPlayerIsARecipient(): void
    {
        $recipient = $this->query->byEmail(PlayerFixture::PLAYER_REGULAR_EMAIL);

        self::assertNotNull($recipient);
        self::assertSame(NewsletterAudience::Player, $recipient->audience);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $recipient->id);
    }

    public function testPlayerWithUnverifiedEmailIsLeftOut(): void
    {
        $this->markUnverified(PlayerFixture::PLAYER_REGULAR);

        self::assertNull($this->query->byEmail(PlayerFixture::PLAYER_REGULAR_EMAIL));
        self::assertNotContains(
            mb_strtolower(PlayerFixture::PLAYER_REGULAR_EMAIL),
            array_map(static fn ($recipient): string => $recipient->email, $this->query->all()),
        );
    }

    public function testConfirmedGuestSignupStillCountsForAnUnverifiedPlayersAddress(): void
    {
        $this->markUnverified(PlayerFixture::PLAYER_REGULAR);
        $guestId = Uuid::uuid7()->toString();
        $this->database->insert('newsletter_subscriber', [
            'id' => $guestId,
            'email' => mb_strtolower(PlayerFixture::PLAYER_REGULAR_EMAIL),
            'locale' => 'en',
            'created_at' => '2026-10-01 10:00:00',
            'status' => NewsletterSubscriberStatus::Confirmed->value,
            'confirmed_at' => '2026-10-01 10:05:00',
        ]);

        $recipient = $this->query->byEmail(PlayerFixture::PLAYER_REGULAR_EMAIL);

        // The guest's own confirmation link proved the address
        self::assertNotNull($recipient);
        self::assertSame(NewsletterAudience::Guest, $recipient->audience);
        self::assertSame($guestId, $recipient->id);
        self::assertTrue($recipient->subscribed);
    }

    private function markUnverified(string $playerId): void
    {
        $this->database->executeStatement(
            'UPDATE user_account SET email_verified_at = NULL WHERE user_id = (SELECT user_id FROM player WHERE id = :id)',
            ['id' => $playerId],
        );
    }
}
