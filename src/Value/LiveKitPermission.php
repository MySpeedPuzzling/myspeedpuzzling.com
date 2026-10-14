<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a participant may do in a LiveKit room - set on somebody already connected through the server API
 * (LiveKitApi::updateParticipant(), the protocol's ParticipantPermission). The whole permission is replaced, so every
 * field is sent.
 */
final readonly class LiveKitPermission
{
    /**
     * @param list<LiveKitTrackSource> $canPublishSources only these may be published (with canPublish)
     */
    public function __construct(
        public bool $canSubscribe,
        public bool $canPublish,
        public bool $canPublishData,
        public array $canPublishSources = [],
        public bool $hidden = false,
    ) {
    }

    /**
     * Watching only - what a studio guest keeps after the host's "Thank you" (EndStudioGuest)
     */
    public static function subscribeOnly(): self
    {
        return new self(canSubscribe: true, canPublish: false, canPublishData: false);
    }

    /**
     * The server API's JSON (the field names of the protocol)
     *
     * @return array{can_subscribe: bool, can_publish: bool, can_publish_data: bool, can_publish_sources: list<string>, hidden: bool}
     */
    public function toProtocol(): array
    {
        return [
            'can_subscribe' => $this->canSubscribe,
            'can_publish' => $this->canPublish,
            'can_publish_data' => $this->canPublishData,
            'can_publish_sources' => array_map(static fn (LiveKitTrackSource $source): string => $source->protocolName(), $this->canPublishSources),
            'hidden' => $this->hidden,
        ];
    }
}
