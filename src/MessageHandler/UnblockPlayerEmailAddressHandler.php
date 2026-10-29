<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\EmailAddressNotBlocked;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\UnblockPlayerEmailAddress;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Removes the whole suppression row: a block by hand may sit on top of a bounce, and lifting it means "send again"
 */
#[AsMessageHandler]
readonly final class UnblockPlayerEmailAddressHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private UserAccountRepository $userAccountRepository,
        private EmailSuppressionRepository $emailSuppressionRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws EmailAddressNotBlocked
     */
    public function __invoke(UnblockPlayerEmailAddress $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $account = $player->userId === null ? null : $this->userAccountRepository->findByUserId($player->userId);
        $suppression = $account === null ? null : $this->emailSuppressionRepository->findByEmail($account->email);

        if ($suppression === null || $suppression->blocksAllMail() === false) {
            throw new EmailAddressNotBlocked();
        }

        $this->emailSuppressionRepository->delete($suppression);
    }
}
