<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PlayerProfile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EditProfileFormData
{
    public null|string $name = null;
    public null|string $email = null;
    public null|string $country = null;
    public null|string $city = null;
    public null|UploadedFile $avatar = null;
    public null|string $bio = null;
    public null|string $facebook = null;
    public null|string $instagram = null;
    public bool $allowDirectMessages = true;
    public bool $emailNotificationsEnabled = true;

    public static function fromPlayerProfile(PlayerProfile $playerProfile): self
    {
        $data = new self();
        $data->name = $playerProfile->playerName;
        $data->email = $playerProfile->email;
        $data->city = $playerProfile->city;
        $data->country = $playerProfile->country;
        $data->bio = $playerProfile->bio;
        $data->facebook = $playerProfile->facebook;
        $data->instagram = $playerProfile->instagram;
        $data->allowDirectMessages = $playerProfile->allowDirectMessages;
        $data->emailNotificationsEnabled = $playerProfile->emailNotificationsEnabled;

        return $data;
    }
}
