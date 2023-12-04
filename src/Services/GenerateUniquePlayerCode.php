<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Nette\Utils\Random;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;

readonly final class GenerateUniquePlayerCode
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     */
    public function generate(): string
    {
        $attempts = 0;

        do {
            $random = Random::generate(6);

            try {
                $this->playerRepository->getByCode($random);
            } catch (PlayerNotFound) {
                return $random;
            }

            $attempts++;
        } while($attempts <= 5);

        throw new CouldNotGenerateUniqueCode('Could not generate unique code, max attempts reached');
    }
}
