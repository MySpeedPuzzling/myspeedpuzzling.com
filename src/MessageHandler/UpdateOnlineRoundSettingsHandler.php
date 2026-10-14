<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\OnlineDetectionModeLocked;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\UpdateOnlineRoundSettings;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The online timing settings of a round, all at once (docs/features/online-competitions/README.md) - only of a round
 * that uses online timing. The settings only: nobody's entry, link or result changes.
 *
 * Finish detection is fixed from the round's start until a Reset (OnlineRound::detectionModeLockedAt()): another mode
 * then is refused before anything changes (OnlineDetectionModeLocked); the same mode with other settings is saved. The
 * open phones are told by the controller once this is committed (OnlineRoundSettingsLiveUpdates) - never from here.
 */
#[AsMessageHandler]
readonly final class UpdateOnlineRoundSettingsHandler
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound
     * @throws OnlineDetectionModeLocked
     */
    public function __invoke(UpdateOnlineRoundSettings $message): void
    {
        $onlineRound = $this->onlineRoundRepository->ofRound($message->roundId) ?? throw new OnlineRoundNotFound();

        // Checked before anything changes - a refused message leaves the entity as it was
        if ($message->detectionMode !== $onlineRound->detectionMode && $onlineRound->detectionModeLockedAt($this->clock->now())) {
            throw new OnlineDetectionModeLocked();
        }

        $onlineRound->changeSettings(
            detectionMode: $message->detectionMode,
            videoEnabled: $message->videoEnabled,
            participantsSeeEachOther: $message->participantsSeeEachOther,
            studioEnabled: $message->studioEnabled,
            recordingEnabled: $message->recordingEnabled,
            unfinishedMeasure: $message->unfinishedMeasure,
            missingPieceRule: $message->missingPieceRule,
        );
    }
}
