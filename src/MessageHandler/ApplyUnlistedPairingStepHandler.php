<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ApplyUnlistedPairingStep;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPairer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One step of a pairing of an unlisted puzzle, one transaction (docs/features/data-import/unlisted-puzzles.md
 * "Pairing") - all of it is UnlistedPairer's.
 */
#[AsMessageHandler]
readonly final class ApplyUnlistedPairingStepHandler
{
    public function __construct(
        private UnlistedPairer $pairer,
    ) {
    }

    /**
     * @return bool for FINISH: whether the pairing is through
     */
    public function __invoke(ApplyUnlistedPairingStep $message): bool
    {
        if ($message->step === ApplyUnlistedPairingStep::FINISH) {
            return $this->pairer->finish($message->pairingId);
        }

        if ($message->subjectId === null) {
            return true;
        }

        if ($message->failed) {
            $this->pairer->failed($message->pairingId, $message->step, $message->subjectId);

            return true;
        }

        match ($message->step) {
            ApplyUnlistedPairingStep::RESULT => $this->pairer->result($message->pairingId, $message->subjectId),
            ApplyUnlistedPairingStep::OWNERSHIP => $this->pairer->ownership($message->pairingId, $message->subjectId),
            default => null,
        };

        return true;
    }
}
