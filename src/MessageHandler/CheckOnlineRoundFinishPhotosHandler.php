<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CheckOnlineFinishPhoto;
use SpeedPuzzling\Web\Message\CheckOnlineRoundFinishPhotos;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvSettings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * A round's finish photos to the completeness check (docs/features/online-competitions/README.md §5.8, Phase 3): one
 * CheckOnlineFinishPhoto per verified `candidate` / `button` / `end` photo - without a verdict yet, or every one when
 * forced (the check itself decides the rest: CheckOnlineFinishPhotoHandler). Nothing while the check is off. Answers the
 * number of photos sent. The round must exist (CompetitionRoundNotFound otherwise).
 */
#[AsMessageHandler]
readonly final class CheckOnlineRoundFinishPhotosHandler
{
    public function __construct(
        private OnlineCvSettings $settings,
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(CheckOnlineRoundFinishPhotos $message): int
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($this->settings->isOn() === false) {
            return 0;
        }

        $mediaIds = $this->onlineEntryMediaRepository->finishPhotoIdsOfRound($round->id->toString(), withoutVerdictOnly: $message->force === false);

        foreach ($mediaIds as $mediaId) {
            $this->messageBus->dispatch(new CheckOnlineFinishPhoto($mediaId, force: $message->force), [new DispatchAfterCurrentBusStamp()]);
        }

        return count($mediaIds);
    }
}
