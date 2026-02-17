<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PlayerProfile;

final class MessagingSettingsFormData
{
    public bool $allowDirectMessages = true;
    public bool $emailNotificationsEnabled = true;

    public static function fromPlayerProfile(PlayerProfile $playerProfile): self
    {
        $data = new self();
        $data->allowDirectMessages = $playerProfile->allowDirectMessages;
        $data->emailNotificationsEnabled = $playerProfile->emailNotificationsEnabled;

        return $data;
    }
}
