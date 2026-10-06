<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleCannotHideEverywhere;
use SpeedPuzzling\Web\Message\KeepRoundPuzzleHiddenEverywhere;
use SpeedPuzzling\Web\Query\MayKeepRoundPuzzleHiddenEverywhere;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Keep it hidden everywhere until …": the round takes over the puzzle's site-wide hide, so the puzzle comes out
 * nowhere before this round reveals it (a puzzle created for the round before the reveal model, whose site-wide hide
 * stayed at an older moment).
 */
#[AsMessageHandler]
readonly final class KeepRoundPuzzleHiddenEverywhereHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private MayKeepRoundPuzzleHiddenEverywhere $mayKeepRoundPuzzleHiddenEverywhere,
        private SecretPuzzleHides $secretPuzzleHides,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RoundPuzzleAlreadyRevealed
     * @throws RoundPuzzleCannotHideEverywhere
     */
    public function __invoke(KeepRoundPuzzleHiddenEverywhere $message): void
    {
        $this->secretPuzzleHides->lockPuzzleOfRoundPuzzle($message->roundPuzzleId);

        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);

        if ($roundPuzzle->isHiddenAt($this->clock->now()) === false) {
            throw new RoundPuzzleAlreadyRevealed();
        }

        if ($this->mayKeepRoundPuzzleHiddenEverywhere->byId($message->roundPuzzleId) === false) {
            throw new RoundPuzzleCannotHideEverywhere();
        }

        $roundPuzzle->keepHiddenEverywhere();
        $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
    }
}
