<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PlayerProfile;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

final class PlayerCodeFormData
{
    #[Length(min: 3, max: 8)]
    #[Regex('/^[a-zA-Z0-9]{3,8}$/', 'player_code')]
    public string $code = '';

    public static function fromPlayerProfile(PlayerProfile $playerProfile): self
    {
        $data = new self();
        $data->code = $playerProfile->code;

        return $data;
    }
}
