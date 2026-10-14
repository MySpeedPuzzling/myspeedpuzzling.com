<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\DeleteOnlineReferenceImage;
use SpeedPuzzling\Web\Message\RemoveOnlineRoundReferenceImage;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The round's reference image goes - the completeness check uses the round puzzle's catalogue image again. Its object is
 * deleted after the commit (DeleteOnlineReferenceImage, async).
 */
#[AsMessageHandler]
readonly final class RemoveOnlineRoundReferenceImageHandler
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound
     */
    public function __invoke(RemoveOnlineRoundReferenceImage $message): void
    {
        $onlineRound = $this->onlineRoundRepository->ofRound($message->roundId) ?? throw new OnlineRoundNotFound();

        $removed = $onlineRound->changeReferenceImage(null);

        if ($removed !== null) {
            $this->messageBus->dispatch(new DeleteOnlineReferenceImage($removed), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
