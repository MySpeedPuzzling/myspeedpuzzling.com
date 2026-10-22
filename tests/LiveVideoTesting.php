<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryStudioInviteSignal;
use SpeedPuzzling\Web\Tests\TestDouble\LiveKitHttpMock;

/**
 * Live video in tests (docs/features/online-competitions/contracts/phase-1b.md): .env.test turns it on with made-up
 * LIVEKIT_* values, LiveKit's server API answers from LiveKitHttpMock and the studio invitation signal is in-process
 * (config/services_test.php). The Online Test Cup's round has its video off (OnlineRoundsFixture) - turnRoundVideoOn()
 * switches it on in the test's transaction.
 */
trait LiveVideoTesting
{
    public const string LIVEKIT_TEST_KEY = 'test-livekit-key';
    public const string LIVEKIT_TEST_SECRET = 'test-livekit-secret-of-at-least-32-characters';
    public const string LIVEKIT_TEST_PUBLIC_URL = 'wss://livekit.test';
    public const string LIVEKIT_TEST_API_URL = 'http://livekit.test:7880';

    /** @var false|null|string the env value before turnLiveVideoOff() - false: unset, null: not changed */
    private null|false|string $originalLiveKitApiUrl = null;

    private static function resetLiveVideoDoubles(): void
    {
        LiveKitHttpMock::reset();
        InMemoryStudioInviteSignal::reset();
    }

    private static function turnRoundVideoOn(Connection $connection, bool $seeEachOther = false): void
    {
        $connection->update('online_round', [
            'video_enabled' => true,
            'participants_see_each_other' => $seeEachOther,
        ], ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO], ['video_enabled' => 'boolean', 'participants_see_each_other' => 'boolean']);
    }

    /**
     * No media server (an empty LIVEKIT_API_URL, production today) - for the kernels booted from now on, until
     * restoreLiveVideo() (call it in tearDown())
     */
    private function turnLiveVideoOff(): void
    {
        if ($this->originalLiveKitApiUrl === null) {
            $original = $_ENV['LIVEKIT_API_URL'] ?? false;
            $this->originalLiveKitApiUrl = is_string($original) ? $original : false;
        }

        $_ENV['LIVEKIT_API_URL'] = '';
        $_SERVER['LIVEKIT_API_URL'] = '';
    }

    private function restoreLiveVideo(): void
    {
        if ($this->originalLiveKitApiUrl === null) {
            return;
        }

        if ($this->originalLiveKitApiUrl === false) {
            unset($_ENV['LIVEKIT_API_URL'], $_SERVER['LIVEKIT_API_URL']);
        } else {
            $_ENV['LIVEKIT_API_URL'] = $this->originalLiveKitApiUrl;
            $_SERVER['LIVEKIT_API_URL'] = $this->originalLiveKitApiUrl;
        }

        $this->originalLiveKitApiUrl = null;
    }

    /**
     * The claims of a token minted with the test key pair - its signature checked
     *
     * @return array<string, mixed>
     */
    private static function liveKitClaims(string $jwt): array
    {
        // Judged at the frozen instant the app minted it on, like the app's own verifiers (php-jwt reads time() otherwise)
        $previousTimestamp = JWT::$timestamp;
        JWT::$timestamp = TestClock::timestamp();

        try {
            $decoded = JWT::decode($jwt, new Key(self::LIVEKIT_TEST_SECRET, 'HS256'));
        } finally {
            JWT::$timestamp = $previousTimestamp;
        }

        $claims = json_decode((string) json_encode($decoded), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }
}
