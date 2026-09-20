<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PrivateProfileViewer;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PrivateProfileViewersLimitReached;
use SpeedPuzzling\Web\Message\AllowPrivateProfileViewer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PrivateProfileViewerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AllowPrivateProfileViewerHandler
{
    public const int MAX_VIEWERS = 200;

    public function __construct(
        private PlayerRepository $playerRepository,
        private PrivateProfileViewerRepository $privateProfileViewerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A block between the two players is deliberately not checked here: an admin-imposed block
     * must never be given away by an error. The row is stored and the read side
     * (PrivateProfileAccess) ignores it for as long as any block exists.
     *
     * @throws PlayerNotFound
     * @throws PrivateProfileViewersLimitReached
     */
    public function __invoke(AllowPrivateProfileViewer $message): void
    {
        $owner = $this->playerRepository->get($message->ownerId);
        $viewer = $this->playerRepository->get($message->viewerId);

        if ($owner->id->equals($viewer->id)) {
            return;
        }

        if ($this->privateProfileViewerRepository->findByOwnerAndViewer($owner, $viewer) !== null) {
            return;
        }

        if ($this->privateProfileViewerRepository->countByOwner($owner) >= self::MAX_VIEWERS) {
            throw new PrivateProfileViewersLimitReached();
        }

        $this->privateProfileViewerRepository->save(new PrivateProfileViewer(
            id: Uuid::uuid7(),
            owner: $owner,
            viewer: $viewer,
            addedAt: $this->clock->now(),
        ));
    }
}
