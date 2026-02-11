<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditSellSwapListSettings;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\SellSwapListSettings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditSellSwapListSettingsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditSellSwapListSettings $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $settings = new SellSwapListSettings(
            description: $message->description,
            currency: $message->currency,
            customCurrency: $message->customCurrency,
            shippingInfo: $message->shippingInfo,
            contactInfo: $message->contactInfo,
            shippingCountries: $message->shippingCountries,
            shippingCost: $message->shippingCost,
        );

        $player->changeSellSwapListSettings($settings);
    }
}
