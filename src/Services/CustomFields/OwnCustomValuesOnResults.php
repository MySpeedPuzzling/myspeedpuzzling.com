<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetCustomValues;
use SpeedPuzzling\Web\Results\OwnCustomValues;
use Symfony\Component\HttpFoundation\Request;

/**
 * The owner-only block of the result detail (docs/features/custom-fields.md): the signed-in viewer's own values on
 * the results they are on. Null when the viewer has no fields for results (remembered in their session - no
 * statement then) and whenever anything goes wrong: the result detail never fails over somebody's private fields.
 */
readonly final class OwnCustomValuesOnResults
{
    public function __construct(
        private ViewerResultFields $viewerResultFields,
        private GetCustomValues $getCustomValues,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $solvingTimeIds results the viewer is on - the caller decides that, the values read is bound
     *                                     to the viewer either way
     */
    public function forViewer(Request $request, string $viewerPlayerId, array $solvingTimeIds): null|OwnCustomValues
    {
        if ($solvingTimeIds === []) {
            return null;
        }

        try {
            $fields = $this->viewerResultFields->of($request, $viewerPlayerId);

            if ($fields === []) {
                return null;
            }

            return new OwnCustomValues($fields, $this->getCustomValues->forViewer($solvingTimeIds, $viewerPlayerId));
        } catch (\Throwable $exception) {
            $this->logger->warning('Custom fields could not be loaded for the result detail - it is shown without them', [
                'playerId' => $viewerPlayerId,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
