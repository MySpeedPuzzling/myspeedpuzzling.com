<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\UnknownCountryCode;
use SpeedPuzzling\Web\Message\SetPlayerCountry;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SetPlayerCountryHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws UnknownCountryCode
     * @throws PlayerNotFound
     */
    public function __invoke(SetPlayerCountry $message): void
    {
        // Validated before anything is loaded or changed: a refused code leaves nothing behind
        $country = CountryCode::fromCode($message->countryCode) ?? throw new UnknownCountryCode($message->countryCode);

        $player = $this->playerRepository->get($message->playerId);
        $player->changeCountry($country);
    }
}
