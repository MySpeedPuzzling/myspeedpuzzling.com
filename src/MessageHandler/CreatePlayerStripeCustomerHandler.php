<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CreatePlayerStripeCustomer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreatePlayerStripeCustomerHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private StripeClient $stripeClient,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(CreatePlayerStripeCustomer $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $customer = $this->stripeClient->customers->create([
            'email' => $player->email,
            'metadata' => [
                'player_id' => $player->id->toString(),
            ],
        ]);

        $player->updateStripeCustomerId($customer->id);
    }
}
