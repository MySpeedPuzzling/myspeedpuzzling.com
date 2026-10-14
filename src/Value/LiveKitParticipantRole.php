<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a participant's device is in the video rooms (contract phase-1b.md §3, `compete_api_video_token`): the device
 * holding their entry publishes its puzzle camera (`p:{participantId}`), any other device of theirs - a laptop next to
 * the phone - only watches (`p:{participantId}:screen`).
 */
enum LiveKitParticipantRole: string
{
    case Camera = 'camera';
    case Screen = 'screen';
}
