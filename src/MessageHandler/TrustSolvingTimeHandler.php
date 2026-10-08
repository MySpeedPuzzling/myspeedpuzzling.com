<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseNotFound;
use SpeedPuzzling\Web\Message\TrustSolvingTime;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeTrust;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Looks fine" in the queue (docs/features/suspicious-time-review.md, "Moderator queue") - on the case as the page
 * showed it; what trusting does is SuspiciousTimeTrust.
 */
#[AsMessageHandler]
readonly final class TrustSolvingTimeHandler
{
    public function __construct(
        private SuspiciousTimeCaseRepository $suspiciousTimeCaseRepository,
        private PlayerRepository $playerRepository,
        private SuspiciousTimeTrust $suspiciousTimeTrust,
    ) {
    }

    /**
     * @throws SuspiciousTimeCaseNotFound
     * @throws PlayerNotFound
     * @throws SuspiciousTimeCaseChanged
     */
    public function __invoke(TrustSolvingTime $message): void
    {
        // Read under the case's row lock: the scan or an edit holding it has written by then
        $case = $this->suspiciousTimeCaseRepository->getForUpdate($message->caseId);
        $moderator = $this->playerRepository->get($message->decidedById);
        $fingerprint = SuspicionFingerprint::ofTime($case->time);

        // Another moderator or the scan decided meanwhile, or the player edited the time since the page was shown
        if (
            $case->status !== $message->seenStatus
            || in_array($case->status, [SuspiciousTimeCaseStatus::Pending, SuspiciousTimeCaseStatus::Marked], true) === false
            || $fingerprint !== $message->seenFingerprint
        ) {
            throw new SuspiciousTimeCaseChanged();
        }

        // A pending case's reasons are about the entry the scan raised - an edited time waits for the next scan
        if ($case->isPending() && $case->fingerprint !== $fingerprint) {
            throw new SuspiciousTimeCaseChanged();
        }

        $this->suspiciousTimeTrust->trust($case, $moderator, $message->note);
    }
}
