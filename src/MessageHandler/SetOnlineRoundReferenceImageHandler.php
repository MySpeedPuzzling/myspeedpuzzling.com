<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\OnlineReferenceImageUnreadable;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\DeleteOnlineReferenceImage;
use SpeedPuzzling\Web\Message\SetOnlineRoundReferenceImage;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReferenceImages;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Stores the organiser's reference image (OnlineReferenceImages: a JPEG under a new key - written before the commit, like
 * every upload of the site; a save that rolls back leaves the object to the orphan sweep) and points the round at it.
 * The reference it replaces is deleted after the commit (DeleteOnlineReferenceImage, async). The verdicts already made
 * stay until the organiser checks the round's finish photos again (CheckOnlineRoundFinishPhotos with force).
 */
#[AsMessageHandler]
readonly final class SetOnlineRoundReferenceImageHandler
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
        private OnlineReferenceImages $referenceImages,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound
     * @throws OnlineReferenceImageUnreadable
     */
    public function __invoke(SetOnlineRoundReferenceImage $message): void
    {
        $onlineRound = $this->onlineRoundRepository->ofRound($message->roundId) ?? throw new OnlineRoundNotFound();

        $round = $onlineRound->round;
        $key = $this->referenceImages->store($message->image, $round->competition->id->toString(), $round->id->toString());
        $replaced = $onlineRound->changeReferenceImage($key);

        if ($replaced !== null) {
            $this->messageBus->dispatch(new DeleteOnlineReferenceImage($replaced), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
