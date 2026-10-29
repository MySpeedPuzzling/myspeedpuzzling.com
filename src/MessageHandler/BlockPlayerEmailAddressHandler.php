<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailSuppression;
use SpeedPuzzling\Web\Exceptions\PlayerHasNoEmailAddress;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\BlockPlayerEmailAddress;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The block is kept on the address, like a bounce suppression: a player who changes the address is free of it at once
 */
#[AsMessageHandler]
readonly final class BlockPlayerEmailAddressHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private UserAccountRepository $userAccountRepository,
        private EmailSuppressionRepository $emailSuppressionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws PlayerHasNoEmailAddress
     */
    public function __invoke(BlockPlayerEmailAddress $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $account = $player->userId === null ? null : $this->userAccountRepository->findByUserId($player->userId);

        if ($account === null || trim($account->email) === '') {
            throw new PlayerHasNoEmailAddress();
        }

        $now = $this->clock->now();
        $suppression = $this->emailSuppressionRepository->findByEmail($account->email);

        if ($suppression !== null) {
            $suppression->blockByHand($now, $message->reason);

            return;
        }

        $this->emailSuppressionRepository->save(EmailSuppression::blockedByHand(Uuid::uuid7(), $account->email, $now, $message->reason));
    }
}
