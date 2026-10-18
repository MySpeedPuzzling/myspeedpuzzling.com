<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DetectPlayerTimezone;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Message\RegisterUser;
use SpeedPuzzling\Web\Message\RegisterWithOauthIdentity;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The player's time zone (docs/features/player-timezone.md): only listed IANA zones are stored, detection fills an
 * empty value only, the player's own choice always wins.
 */
final class PlayerTimezoneHandlersTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testDetectionFillsAnEmptyTimezone(): void
    {
        self::assertNull($this->storedTimezone(PlayerFixture::PLAYER_REGULAR));

        $this->messageBus->dispatch(new DetectPlayerTimezone(PlayerFixture::PLAYER_REGULAR, 'Europe/Prague'));

        self::assertSame('Europe/Prague', $this->storedTimezone(PlayerFixture::PLAYER_REGULAR));
    }

    public function testDetectionNeverOverwritesTheChosenTimezone(): void
    {
        self::assertSame('Europe/London', $this->storedTimezone(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->messageBus->dispatch(new DetectPlayerTimezone(PlayerFixture::PLAYER_WITH_STRIPE, 'America/New_York'));

        self::assertSame('Europe/London', $this->storedTimezone(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testDetectionIgnoresAnUnknownIdentifier(): void
    {
        foreach (['Mars/Olympus_Mons', '', '   ', 'europe/prague', "Europe/Prague'; DROP TABLE player; --", str_repeat('A', 200)] as $value) {
            $this->messageBus->dispatch(new DetectPlayerTimezone(PlayerFixture::PLAYER_REGULAR, $value));
        }

        $this->messageBus->dispatch(new DetectPlayerTimezone(PlayerFixture::PLAYER_REGULAR, null));

        self::assertNull($this->storedTimezone(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAnOldNameABrowserReportsIsStoredUnderTheListedOne(): void
    {
        // Chrome reports the CLDR name, PHP lists the current tzdata one
        $this->messageBus->dispatch(new DetectPlayerTimezone(PlayerFixture::PLAYER_REGULAR, 'Asia/Calcutta'));

        self::assertSame('Asia/Kolkata', $this->storedTimezone(PlayerFixture::PLAYER_REGULAR));
    }

    public function testEditProfileSetsChangesAndClearsTheTimezone(): void
    {
        $this->messageBus->dispatch($this->editProfile(PlayerFixture::PLAYER_WITH_STRIPE, 'America/New_York'));
        self::assertSame('America/New_York', $this->storedTimezone(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->messageBus->dispatch($this->editProfile(PlayerFixture::PLAYER_WITH_STRIPE, null));
        self::assertNull($this->storedTimezone(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testEditProfileIgnoresAnUnknownIdentifier(): void
    {
        $this->messageBus->dispatch($this->editProfile(PlayerFixture::PLAYER_WITH_STRIPE, 'Nowhere/Land'));

        self::assertSame('Europe/London', $this->storedTimezone(PlayerFixture::PLAYER_WITH_STRIPE), 'Kept as it was');
        self::assertSame('London', $this->connection->fetchOne('SELECT city FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]), 'The rest of the profile is saved');
    }

    public function testNativeRegistrationStoresTheDetectedTimezone(): void
    {
        $listed = $this->registerNative('tz.native.one@example.com', 'Europe/Prague');
        $unknown = $this->registerNative('tz.native.two@example.com', 'Not/A_Zone');
        $missing = $this->registerNative('tz.native.three@example.com', null);

        self::assertSame('Europe/Prague', $this->storedTimezoneOfUser($listed));
        self::assertNull($this->storedTimezoneOfUser($unknown));
        self::assertNull($this->storedTimezoneOfUser($missing));
    }

    public function testSocialRegistrationStoresTheDetectedTimezone(): void
    {
        $userId = $this->dispatchForUserId(new RegisterWithOauthIdentity(
            provider: OauthProvider::Google,
            providerUserId: 'google-tz-1',
            email: 'tz.social@example.com',
            emailVerified: true,
            name: null,
            locale: 'en',
            termsAccepted: true,
            timezone: 'Asia/Tokyo',
        ));

        self::assertSame('Asia/Tokyo', $this->storedTimezoneOfUser($userId));
    }

    private function editProfile(string $playerId, null|string $timezone): EditProfile
    {
        return new EditProfile(
            playerId: $playerId,
            name: PlayerFixture::PLAYER_WITH_STRIPE_NAME,
            country: 'gb',
            city: 'London',
            avatar: null,
            bio: null,
            facebook: null,
            instagram: null,
            twitch: null,
            timezone: $timezone,
        );
    }

    private function registerNative(string $email, null|string $timezone): string
    {
        return $this->dispatchForUserId(new RegisterUser(
            email: $email,
            plainPassword: 'a-strong-passphrase',
            locale: 'en',
            termsAccepted: true,
            timezone: $timezone,
        ));
    }

    private function dispatchForUserId(object $message): string
    {
        $handledStamp = $this->messageBus->dispatch($message)->last(HandledStamp::class);
        self::assertNotNull($handledStamp);
        $userId = $handledStamp->getResult();
        self::assertIsString($userId);

        return $userId;
    }

    private function storedTimezone(string $playerId): mixed
    {
        return $this->connection->fetchOne('SELECT timezone FROM player WHERE id = :id', ['id' => $playerId]);
    }

    private function storedTimezoneOfUser(string $userId): mixed
    {
        return $this->connection->fetchOne('SELECT timezone FROM player WHERE user_id = :userId', ['userId' => $userId]);
    }
}
