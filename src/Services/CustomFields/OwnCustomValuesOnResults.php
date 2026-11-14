<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetCustomValues;
use SpeedPuzzling\Web\Results\OwnCustomValues;
use SpeedPuzzling\Web\Results\PuzzleResultAttempt;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The owner-only block of the result detail (docs/features/custom-fields.md): the signed-in viewer's own values on
 * the attempts they are on.
 *
 * **Whose values: the signed-in player's, resolved here** - there is no player id to pass, so a caller can not hand
 * out somebody else's (a profile page with the profile owner's id, say). Which attempts: only those the viewer
 * tracked or is a registered member of.
 *
 * Null for a guest, for somebody else's results, for a viewer without fields for results (remembered in their
 * session - no statement then) and whenever anything goes wrong: the result detail never fails over somebody's
 * private fields.
 */
readonly final class OwnCustomValuesOnResults
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private ViewerResultFields $viewerResultFields,
        private GetCustomValues $getCustomValues,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<PuzzleResultAttempt> $attempts
     */
    public function ofSignedInViewer(Request $request, array $attempts): null|OwnCustomValues
    {
        try {
            $viewerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

            if ($viewerId === null) {
                return null;
            }

            $solvingTimeIds = [];

            foreach ($attempts as $attempt) {
                if ($attempt->trackedByPlayerId === $viewerId || in_array($viewerId, $attempt->memberPlayerIds, true)) {
                    $solvingTimeIds[] = $attempt->timeId;
                }
            }

            if ($solvingTimeIds === []) {
                return null;
            }

            $fields = $this->viewerResultFields->of($request, $viewerId);

            if ($fields === []) {
                return null;
            }

            return new OwnCustomValues($fields, $this->getCustomValues->forViewer($solvingTimeIds, $viewerId));
        } catch (\Throwable $exception) {
            // Reading carries no value of anybody (the statement's parameters are ids) - logged as it is
            $this->logger->warning('Custom fields could not be loaded for the result detail - it is shown without them', [
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
