<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PlayerProfile;

final class PlayerVisibilityFormData
{
    public bool $isPrivate = false;

    public static function fromPlayerProfile(PlayerProfile $playerProfile): self
    {
        $data = new self();
        $data->isPrivate = $playerProfile->isPrivate;

        return $data;
    }
}
