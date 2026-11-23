<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundPuzzleNotFound;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Switches "Unboxed" on or off - for the whole round, or per puzzle (spws-unboxed-per-puzzle.md) - and re-syncs the
 * site-wide hide of the round's secret puzzles (an unboxed round or puzzle keeps the picture of one it hides everywhere
 * until the round's results are first published - SecretPuzzleHides::hideOf()). Nothing changed = nothing written.
 */
#[AsMessageHandler]
readonly final class ChangeRoundUnboxedHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private SecretPuzzleHides $secretPuzzleHides,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound also a round of another event than the caller authorised
     * @throws CompetitionRoundPuzzleNotFound a listed round puzzle that is not one of this round's (nothing written)
     */
    public function __invoke(ChangeRoundUnboxed $message): void
    {
        // Locks the round, then its secret puzzles - waits for every other change of them, then reads fresh
        $secretPuzzleIds = $this->secretPuzzleHides->lockRoundsForChange([$message->roundId]);

        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $changed = false;

        if ($message->unboxedRoundPuzzleIds !== null) {
            $wanted = array_map(strtolower(...), $message->unboxedRoundPuzzleIds);
            $known = [];

            foreach ($round->roundPuzzles as $roundPuzzle) {
                $known[] = $roundPuzzle->id->toString();
            }

            if (array_diff($wanted, $known) !== []) {
                throw new CompetitionRoundPuzzleNotFound();
            }

            foreach ($round->roundPuzzles as $roundPuzzle) {
                $unboxed = in_array($roundPuzzle->id->toString(), $wanted, true);

                if ($roundPuzzle->unboxed !== $unboxed) {
                    $roundPuzzle->changeUnboxed($unboxed);
                    $changed = true;
                }
            }
        }

        if ($round->unboxed !== $message->unboxed) {
            $round->changeUnboxed($message->unboxed);
            $changed = true;
        }

        if ($changed) {
            $this->secretPuzzleHides->resyncByIds($secretPuzzleIds);
        }
    }
}
