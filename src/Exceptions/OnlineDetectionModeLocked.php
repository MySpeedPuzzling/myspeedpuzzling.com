<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A change of a round's finish detection refused: it is fixed from the round's start - a scheduled start or a countdown
 * included - until the organiser's Reset (OnlineRound::detectionModeLockedAt(), docs/features/online-competitions/
 * README.md §5.7). The phones decided at GO what they do with raised hands; switching it under them would leave every
 * phone with another rule than the review. Thrown before anything was written - nothing of the settings changed. An HTTP
 * exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class OnlineDetectionModeLocked extends ConflictHttpException
{
    public const string TRANSLATION_KEY = 'online_rounds.settings.detection.locked_refused';

    public function __construct()
    {
        parent::__construct('Finish detection is fixed from the round\'s start until a Reset. Nothing was changed.');
    }
}
