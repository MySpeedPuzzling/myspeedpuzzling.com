<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveKit;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitTokens;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccess;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\LiveKitParticipantRole;
use SpeedPuzzling\Web\Value\LiveKitRoomKind;
use SpeedPuzzling\Web\Value\OnlineAccessContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The access tokens of the video rooms (docs/features/online-competitions/contracts/phase-1b.md §3): the identity,
 * room and grants of every role, signed with the key pair (HS256), valid six hours from the clock's now.
 */
final class LiveKitTokensTest extends KernelTestCase
{
    private const string KEY = 'test-key';
    private const string SECRET = 'a-test-secret-that-is-long-enough-for-hs256';
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string NOW = '2026-10-13 18:00:00 UTC';

    private LiveKitTokens $tokens;
    private OnlineAccessContext $olivia;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->olivia = self::getContainer()->get(OnlineAccess::class)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);
        $this->tokens = new LiveKitTokens(
            new LiveKitSettings('http://livekit:7880', 'wss://live.example.test/', self::KEY, self::SECRET),
            new MockClock(new DateTimeImmutable(self::NOW)),
        );
    }

    public function testAParticipantsCameraPublishesItsCameraOnlyInTheHall(): void
    {
        $token = $this->tokens->participant($this->olivia, 'phone-1', LiveKitRoomKind::Hall);

        self::assertSame('wss://live.example.test', $token->url, 'the public URL, without its trailing slash');
        self::assertSame('p:' . OnlineRoundsFixture::PARTICIPANT_CONNECTED, $token->identity);
        self::assertSame('round-' . self::ROUND, $token->room);
        self::assertSame(['url' => $token->url, 'token' => $token->token, 'identity' => $token->identity, 'room' => $token->room], $token->toArray());

        $claims = self::claims($token->token);
        self::assertSame(self::KEY, $claims['iss']);
        self::assertSame($token->identity, $claims['sub']);
        self::assertSame('Olivia Online', $claims['name']);
        self::assertSame(strtotime(self::NOW), $claims['nbf']);
        self::assertSame(strtotime(self::NOW) + 6 * 3600, $claims['exp']);
        self::assertSame((strtotime(self::NOW) + 6 * 3600) * 1000, $token->expiresAtMs);
        self::assertSame([
            'room' => 'round-' . self::ROUND,
            'roomJoin' => true,
            'canPublish' => true,
            'canPublishSources' => ['camera'],
            'canSubscribe' => true,
            'canPublishData' => false,
        ], $claims['video']);
        self::assertSame(['role' => 'camera', 'deviceId' => 'phone-1'], $claims['attributes']);
    }

    public function testAParticipantInTheStudioPublishesCameraAndMicrophone(): void
    {
        $claims = self::claims($this->tokens->participant($this->olivia, 'phone-1', LiveKitRoomKind::Studio)->token);

        self::assertSame('studio-' . self::ROUND, self::video($claims)['room']);
        self::assertSame(['camera', 'microphone'], self::video($claims)['canPublishSources']);
        self::assertFalse(self::video($claims)['canPublishData']);
    }

    /**
     * The studio's safety (contract phase-1b.md §8): a participant's studio token - camera or second screen - is good
     * for joining only, two minutes; LiveKit refreshes the token of a joined connection itself. The hall's tokens and
     * the host's keep their six hours.
     */
    public function testAParticipantsStudioTokenLivesTwoMinutes(): void
    {
        foreach ([LiveKitParticipantRole::Camera, LiveKitParticipantRole::Screen] as $role) {
            $token = $this->tokens->participant($this->olivia, 'phone-1', LiveKitRoomKind::Studio, $role);
            $claims = self::claims($token->token);

            self::assertSame(strtotime(self::NOW), $claims['nbf'], $role->value);
            self::assertSame(strtotime(self::NOW) + 120, $claims['exp'], $role->value);
            self::assertSame((strtotime(self::NOW) + 120) * 1000, $token->expiresAtMs, $role->value);
        }

        self::assertSame(strtotime(self::NOW) + 6 * 3600, self::claims($this->tokens->participant($this->olivia, 'phone-1', LiveKitRoomKind::Hall, LiveKitParticipantRole::Screen)->token)['exp']);
        self::assertSame(strtotime(self::NOW) + 6 * 3600, self::claims($this->tokens->moderator(PlayerFixture::PLAYER_ADMIN, self::ROUND, LiveKitRoomKind::Studio)->token)['exp']);
    }

    public function testASecondScreenOnlyWatches(): void
    {
        foreach ([LiveKitRoomKind::Hall, LiveKitRoomKind::Studio] as $room) {
            $token = $this->tokens->participant($this->olivia, 'laptop-1', $room, LiveKitParticipantRole::Screen);
            $claims = self::claims($token->token);

            self::assertSame('p:' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . ':screen', $claims['sub']);
            self::assertSame([
                'room' => $room->roomName(self::ROUND),
                'roomJoin' => true,
                'canPublish' => false,
                'canSubscribe' => true,
                'canPublishData' => false,
            ], $claims['video']);
            self::assertSame(['role' => 'screen', 'deviceId' => 'laptop-1'], $claims['attributes']);
        }
    }

    public function testAModeratorPublishesEverythingAndRunsTheStudio(): void
    {
        $hall = self::claims($this->tokens->moderator(PlayerFixture::PLAYER_ADMIN, strtoupper(self::ROUND), LiveKitRoomKind::Hall, 'Host')->token);

        self::assertSame('m:' . PlayerFixture::PLAYER_ADMIN, $hall['sub']);
        self::assertSame('Host', $hall['name']);
        self::assertSame([
            'room' => 'round-' . self::ROUND,
            'roomJoin' => true,
            'canPublish' => true,
            'canPublishSources' => ['camera', 'microphone', 'screen_share'],
            'canSubscribe' => true,
            'canPublishData' => true,
        ], $hall['video']);
        self::assertArrayNotHasKey('attributes', $hall);

        $studio = self::claims($this->tokens->moderator(PlayerFixture::PLAYER_ADMIN, self::ROUND, LiveKitRoomKind::Studio)->token);
        self::assertSame('studio-' . self::ROUND, self::video($studio)['room']);
        self::assertTrue(self::video($studio)['roomAdmin']);
        self::assertArrayNotHasKey('name', $studio, 'no name given');
    }

    public function testASpectatorWatchesTheStudioHidden(): void
    {
        $first = $this->tokens->spectator(self::ROUND);
        $second = $this->tokens->spectator(self::ROUND);

        self::assertMatchesRegularExpression('/^s:[0-9a-f]{16}$/', $first->identity);
        self::assertNotSame($first->identity, $second->identity);

        $claims = self::claims($first->token);
        self::assertSame($first->identity, $claims['sub']);
        self::assertSame([
            'room' => 'studio-' . self::ROUND,
            'roomJoin' => true,
            'canPublish' => false,
            'canSubscribe' => true,
            'canPublishData' => false,
            'hidden' => true,
        ], $claims['video']);
        self::assertArrayNotHasKey('name', $claims);
        self::assertSame(strtotime(self::NOW) + 6 * 3600, $claims['exp']);
    }

    public function testTheServersOwnTokensLiveAMinute(): void
    {
        $claims = self::claims($this->tokens->server(['room' => 'studio-' . self::ROUND, 'roomAdmin' => true]));

        self::assertSame(self::KEY, $claims['iss']);
        self::assertArrayNotHasKey('sub', $claims);
        self::assertSame(strtotime(self::NOW) + 60, $claims['exp']);
        self::assertSame(['room' => 'studio-' . self::ROUND, 'roomAdmin' => true], $claims['video']);
    }

    public function testATokenOfAnotherSecretIsRefused(): void
    {
        $token = $this->tokens->spectator(self::ROUND)->token;

        $this->expectException(SignatureInvalidException::class);
        self::decode($token, 'another-secret-that-is-long-enough-for-hs256');
    }

    /**
     * @return array<string, mixed>
     */
    private static function claims(string $jwt): array
    {
        $claims = json_decode((string) json_encode(self::decode($jwt, self::SECRET)), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private static function video(array $claims): array
    {
        $video = $claims['video'] ?? null;
        self::assertIsArray($video);

        /** @var array<string, mixed> $video */
        return $video;
    }

    private static function decode(string $jwt, string $secret): object
    {
        $previous = JWT::$timestamp;
        JWT::$timestamp = strtotime(self::NOW);

        try {
            return JWT::decode($jwt, new Key($secret, 'HS256'));
        } finally {
            JWT::$timestamp = $previous;
        }
    }
}
