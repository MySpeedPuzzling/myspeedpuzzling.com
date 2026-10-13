<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use Firebase\JWT\JWT;
use LogicException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\LiveKitIdentity;
use SpeedPuzzling\Web\Value\LiveKitParticipantRole;
use SpeedPuzzling\Web\Value\LiveKitRoomKind;
use SpeedPuzzling\Web\Value\LiveKitToken;
use SpeedPuzzling\Web\Value\LiveKitTrackSource;
use SpeedPuzzling\Web\Value\OnlineAccessContext;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * The access tokens of the online competitions' LiveKit rooms (docs/features/online-competitions/contracts/phase-1b.md
 * §3) - JWTs signed with LIVEKIT_API_SECRET (HS256), issued by LIVEKIT_API_KEY, valid TTL_SECONDS. Minted only after
 * the caller checked the right to them (the personal link + the device, ONLINE_ROUNDS_MANAGE, the public watch page):
 *
 * | Identity | Hall `round-{id}` | Studio `studio-{id}` |
 * |---|---|---|
 * | `p:{participantId}` (camera) | publish the camera only, subscribe | camera + microphone, subscribe (a joined invitation) |
 * | `p:{participantId}:screen` | subscribe only | subscribe only (a joined invitation) |
 * | `m:{playerId}` | camera, microphone, screen share, data, subscribe | the same + room admin |
 * | `s:{random}` | - | subscribe only, hidden |
 *
 * Participants never publish data (`canPublishData` false): what reaches them as data comes from the server
 * (LiveKitApi::sendData()) or a moderator. Who may watch a participant's camera is the participant's own
 * setTrackSubscriptionPermissions() (assets/online/livekit_session.js): moderators only, or everybody when the round
 * lets participants see each other.
 *
 * server() mints the short-lived tokens of LiveKitApi's own calls.
 */
final readonly class LiveKitTokens
{
    public const int TTL_SECONDS = 6 * 3600;
    public const int SERVER_TTL_SECONDS = 60;
    public const string ALGORITHM = 'HS256';

    public function __construct(
        private LiveKitSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A participant's device in the round their personal link is about now ($context->round): the camera role is the
     * device holding their entry, the screen role any other - the caller decided which one asks.
     */
    public function participant(
        OnlineAccessContext $context,
        string $deviceId,
        LiveKitRoomKind $room,
        LiveKitParticipantRole $role = LiveKitParticipantRole::Camera,
    ): LiveKitToken {
        $round = $context->round ?? throw new LogicException('A video token needs the round the personal link is about');
        $participantId = $context->participant->id->toString();
        $camera = $role === LiveKitParticipantRole::Camera;

        $sources = match (true) {
            $camera === false => [],
            $room === LiveKitRoomKind::Studio => [LiveKitTrackSource::Camera, LiveKitTrackSource::Microphone],
            default => [LiveKitTrackSource::Camera],
        };

        return $this->mint(
            identity: $camera ? LiveKitIdentity::participant($participantId) : LiveKitIdentity::participantScreen($participantId),
            name: $context->participant->name,
            room: $room->roomName($round->id->toString()),
            grant: [
                ...self::publishing($sources),
                'canSubscribe' => true,
                'canPublishData' => false,
            ],
            attributes: ['role' => $role->value, 'deviceId' => $deviceId],
        );
    }

    /**
     * A moderator or host of the round (ONLINE_ROUNDS_MANAGE): everything in the hall, and the studio's admin
     */
    public function moderator(string $playerId, string $roundId, LiveKitRoomKind $room, null|string $name = null): LiveKitToken
    {
        $grant = [
            ...self::publishing([LiveKitTrackSource::Camera, LiveKitTrackSource::Microphone, LiveKitTrackSource::ScreenShare]),
            'canSubscribe' => true,
            'canPublishData' => true,
        ];

        if ($room === LiveKitRoomKind::Studio) {
            $grant['roomAdmin'] = true;
        }

        return $this->mint(
            identity: LiveKitIdentity::moderator($playerId),
            name: $name,
            room: $room->roomName($roundId),
            grant: $grant,
        );
    }

    /**
     * A spectator of the round's studio: watching only, hidden from everybody in the room
     */
    public function spectator(string $roundId): LiveKitToken
    {
        return $this->mint(
            identity: LiveKitIdentity::spectator(bin2hex(random_bytes(8))),
            name: null,
            room: LiveKitRoomKind::Studio->roomName($roundId),
            grant: [
                'canPublish' => false,
                'canSubscribe' => true,
                'canPublishData' => false,
                'hidden' => true,
            ],
        );
    }

    /**
     * A token for one call of the server API (LiveKitApi) - `roomAdmin` of a room, `roomCreate`, …
     *
     * @param array<string, bool|string> $videoGrant
     */
    public function server(array $videoGrant, int $ttlSeconds = self::SERVER_TTL_SECONDS): string
    {
        $now = $this->clock->now()->getTimestamp();

        return JWT::encode([
            'iss' => $this->settings->apiKey(),
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'video' => $videoGrant,
        ], $this->settings->apiSecret(), self::ALGORITHM);
    }

    /**
     * @param array<string, bool|string|list<string>> $grant
     * @param array<string, string> $attributes
     */
    private function mint(string $identity, null|string $name, string $room, array $grant, array $attributes = []): LiveKitToken
    {
        $now = $this->clock->now();
        $issuedAt = $now->getTimestamp();
        $payload = [
            'iss' => $this->settings->apiKey(),
            'sub' => $identity,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + self::TTL_SECONDS,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                ...$grant,
            ],
        ];

        if ($name !== null && trim($name) !== '') {
            $payload['name'] = $name;
        }

        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }

        return new LiveKitToken(
            url: $this->settings->publicUrl(),
            token: JWT::encode($payload, $this->settings->apiSecret(), self::ALGORITHM),
            identity: $identity,
            room: $room,
            expiresAtMs: RoundClock::milliseconds($now) + self::TTL_SECONDS * 1000,
        );
    }

    /**
     * May publish exactly these sources - none: may not publish at all
     *
     * @param list<LiveKitTrackSource> $sources
     * @return array{canPublish: bool, canPublishSources?: list<string>}
     */
    private static function publishing(array $sources): array
    {
        if ($sources === []) {
            return ['canPublish' => false];
        }

        return [
            'canPublish' => true,
            'canPublishSources' => array_map(static fn (LiveKitTrackSource $source): string => $source->value, $sources),
        ];
    }
}
