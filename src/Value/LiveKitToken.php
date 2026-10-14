<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * An access token to one LiveKit room (LiveKitTokens): where the browser connects (`url`, LIVEKIT_PUBLIC_URL), the
 * signed JWT, the identity it carries and the room's name - the answer of every video token endpoint
 * (contract phase-1b.md §5).
 */
final readonly class LiveKitToken
{
    public function __construct(
        public string $url,
        public string $token,
        public string $identity,
        public string $room,
        public int $expiresAtMs,
    ) {
    }

    /**
     * @return array{url: string, token: string, identity: string, room: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'token' => $this->token,
            'identity' => $this->identity,
            'room' => $this->room,
        ];
    }
}
