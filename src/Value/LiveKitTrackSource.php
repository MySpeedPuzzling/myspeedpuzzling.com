<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The kinds of track a participant may publish in a LiveKit room (the `canPublishSources` grant): lower case in access
 * tokens, the protocol's enum names (`CAMERA`, …) in the server API.
 */
enum LiveKitTrackSource: string
{
    case Camera = 'camera';
    case Microphone = 'microphone';
    case ScreenShare = 'screen_share';

    /**
     * The name in the server API's JSON (TrackSource of the LiveKit protocol)
     */
    public function protocolName(): string
    {
        return strtoupper($this->value);
    }
}
