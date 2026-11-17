<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The internal API asked to change a round's live video switches (`videoEnabled`, `participantsSeeEachOther`) while no
 * media server is configured (LiveKitSettings) - the "Online timing" page does not even show them then and keeps their
 * values. Answered 422, nothing changed (docs/features/internal-api.md "Online timing").
 */
final class OnlineVideoNotConfigured extends UnprocessableEntityHttpException implements InternalApiErrorCode
{
    public function __construct()
    {
        parent::__construct('Live video is not configured on this installation (no media server) - "videoEnabled" and "participantsSeeEachOther" keep their values. Nothing was changed.');
    }

    public function internalApiErrorCode(): string
    {
        return 'video_not_configured';
    }
}
