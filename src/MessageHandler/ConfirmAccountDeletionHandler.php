<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\AccountDeletionTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidAccountDeletionToken;
use SpeedPuzzling\Web\Message\ConfirmAccountDeletion;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\ValidateAccountDeletionToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The point of no return: a valid, unexpired token deletes the account it was
 * issued for. The heavy lifting stays in DeletePlayerHandler (the one place that
 * knows how to anonymise and remove a player's data); it runs nested in this
 * handler's transaction, so validating the token and consuming it (the account
 * row goes, the request rows cascade with it) are one atomic step.
 */
#[AsMessageHandler]
final readonly class ConfirmAccountDeletionHandler
{
    public function __construct(
        private ValidateAccountDeletionToken $validateAccountDeletionToken,
        private PlayerRepository $playerRepository,
        private UserAccountRepository $userAccountRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InvalidAccountDeletionToken
     * @throws AccountDeletionTokenExpired
     */
    public function __invoke(ConfirmAccountDeletion $message): void
    {
        $userAccount = $this->validateAccountDeletionToken->validate($message->token);
        $player = $this->playerRepository->findByUserId($userAccount->userId);

        if ($player === null) {
            // Registration creates the pair atomically, so this is a safety net: an
            // account without a player row still holds the login e-mail + hash and
            // must not survive a deletion the user confirmed
            $this->userAccountRepository->remove($userAccount);

            $this->logger->info('User account without a player deleted on user request', [
                'user_id' => $userAccount->userId,
            ]);

            return;
        }

        $this->messageBus->dispatch(new DeletePlayer($player->id->toString()));
    }
}
