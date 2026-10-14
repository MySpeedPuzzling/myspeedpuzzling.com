<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a participant's phone reports (docs/features/online-competitions/README.md §5.6, implementation-plan.md §3.1).
 * An unknown type is refused, never fatal.
 */
enum OnlineEventType: string
{
    case ConsentGiven = 'consent.given';
    case DeviceReady = 'device.ready';
    case CountdownSeen = 'countdown.seen';
    case StartSeen = 'start.seen';
    case FinishCandidate = 'finish.candidate';
    case FinishButton = 'finish.button';
    case FinishButtonUndone = 'finish.button.undone';
    case CameraLost = 'camera.lost';
    case CameraBack = 'camera.back';
    case PageHidden = 'page.hidden';
    case PageVisible = 'page.visible';
    case NetworkOffline = 'network.offline';
    case NetworkOnline = 'network.online';
    case TimeUpPieces = 'timeup.pieces';
    case MediaUploaded = 'media.uploaded';
    // Phase 2 (stream P2F): after "I'm ready" the phone's finish detector started or stopped watching the round
    case DetectorChanged = 'detector.changed';
}
