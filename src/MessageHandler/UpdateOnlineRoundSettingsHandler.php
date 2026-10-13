<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\UpdateOnlineRoundSettings;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The online timing settings of a round, all at once (docs/features/online-competitions/README.md) - only of a round
 * that uses online timing. The settings only: nobody's entry, link or result changes.
 */
#[AsMessageHandler]
readonly final class UpdateOnlineRoundSettingsHandler
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound
     */
    public function __invoke(UpdateOnlineRoundSettings $message): void
    {
        $onlineRound = $this->onlineRoundRepository->ofRound($message->roundId) ?? throw new OnlineRoundNotFound();

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
