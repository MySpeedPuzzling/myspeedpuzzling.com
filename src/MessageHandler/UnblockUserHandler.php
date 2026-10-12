<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\UserBlockNotFound;
use SpeedPuzzling\Web\Message\PushInboxBadge;
use SpeedPuzzling\Web\Message\UnblockUser;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use SpeedPuzzling\Web\Value\UserBlockSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

#[AsMessageHandler]
readonly final class UnblockUserHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private UserBlockRepository $userBlockRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws UserBlockNotFound
     */
    public function __invoke(UnblockUser $message): void
    {
        $blocker = $this->playerRepository->get($message->blockerId);
        $blocked = $this->playerRepository->get($message->blockedId);

        $userBlock = $this->userBlockRepository->getByBlockerAndBlocked($blocker, $blocked);

        // An admin-imposed block is not the blocker's to lift - and they must not learn it exists,
        // so it answers exactly like no block at all
        if ($userBlock->source !== UserBlockSource::Self) {
            throw new UserBlockNotFound();
        }

        $this->userBlockRepository->remove($userBlock);

        // The unblocked player's unread items count again in the blocker's badge (INB-02 review Q2)
        $this->messageBus->dispatch(new PushInboxBadge($blocker->id->toString()), [new DispatchAfterCurrentBusStamp()]);
    }
}
