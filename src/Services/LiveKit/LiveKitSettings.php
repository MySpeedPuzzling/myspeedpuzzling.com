<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the LiveKit server of the online competitions' live video is (docs/features/online-competitions/README.md
 * §5.12, contract phase-1b.md §2) - the LIVEKIT_* env vars (.env explains how to turn it on locally):
 * - `LIVEKIT_API_URL` the server API as this app reaches it (Twirp over HTTP, LiveKitApi);
 * - `LIVEKIT_PUBLIC_URL` what browsers connect to (`ws://…` / `wss://…`, the `url` of every video token);
 * - `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` the key pair tokens are signed with and webhooks are checked against.
 *
 * Live video is available only with all four set - an empty LIVEKIT_API_URL (the committed default, production until
 * its server exists - ADR D78) means video is off: every video endpoint answers 409 `video_unavailable` and no page
 * shows video. A secret shorter than MIN_SECRET_LENGTH counts as not set: HS256 needs a 256-bit key (firebase/php-jwt
 * refuses shorter ones both ways).
 */
final readonly class LiveKitSettings
{
    public const int MIN_SECRET_LENGTH = 32;

    private string $apiUrl;
    private string $publicUrl;
    private string $apiKey;
    private string $apiSecret;

    public function __construct(
        #[Autowire(env: 'LIVEKIT_API_URL')]
        string $apiUrl,
        #[Autowire(env: 'LIVEKIT_PUBLIC_URL')]
        string $publicUrl,
        #[Autowire(env: 'LIVEKIT_API_KEY')]
        string $apiKey,
        #[Autowire(env: 'LIVEKIT_API_SECRET')]
        #[\SensitiveParameter]
        string $apiSecret,
    ) {
        $this->apiUrl = rtrim(trim($apiUrl), '/');
        $this->publicUrl = rtrim(trim($publicUrl), '/');
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
    }

    public function isAvailable(): bool
    {
        return $this->apiUrl !== ''
            && $this->publicUrl !== ''
            && $this->apiKey !== ''
            && strlen($this->apiSecret) >= self::MIN_SECRET_LENGTH;
    }

    public function apiUrl(): string
    {
        return $this->apiUrl;
    }

    public function publicUrl(): string
    {
        return $this->publicUrl;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function apiSecret(): string
    {
        return $this->apiSecret;
    }
}
