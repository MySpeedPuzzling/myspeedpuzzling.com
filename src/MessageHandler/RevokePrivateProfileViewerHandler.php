<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RevokePrivateProfileViewer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PrivateProfileViewerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RevokePrivateProfileViewerHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PrivateProfileViewerRepository $privateProfileViewerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(RevokePrivateProfileViewer $message): void
    {
        $owner = $this->playerRepository->get($message->ownerId);
        $viewer = $this->playerRepository->get($message->viewerId);

        $privateProfileViewer = $this->privateProfileViewerRepository->findByOwnerAndViewer($owner, $viewer);

        if ($privateProfileViewer === null) {
            return;
        }

        $this->privateProfileViewerRepository->remove($privateProfileViewer);
    }
}
