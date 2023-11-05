<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PlayerProfile;

final class EditProfileFormData
{
    public null|string $name = null;
    public null|string $email = null;
    public null|string $country = null;
    public null|string $city = null;

    public static function fromPlayerProfile(PlayerProfile $playerProfile): self
    {
        $data = new self();
        $data->name = $playerProfile->playerName;
        $data->email = $playerProfile->email;
        $data->city = $playerProfile->city;
        $data->country = $playerProfile->country;

        return $data;
    }
}
