<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;

final class MessagingSettingsFormData
{
    public bool $allowDirectMessages = true;
    public bool $emailNotificationsEnabled = true;
    public EmailNotificationFrequency $emailNotificationFrequency = EmailNotificationFrequency::TwentyFourHours;
    public bool $newsletterEnabled = true;

    public static function fromPlayerProfile(PlayerProfile $playerProfile): self
    {
        $data = new self();
        $data->allowDirectMessages = $playerProfile->allowDirectMessages;
        $data->emailNotificationsEnabled = $playerProfile->emailNotificationsEnabled;
        $data->emailNotificationFrequency = $playerProfile->emailNotificationFrequency;
        $data->newsletterEnabled = $playerProfile->newsletterEnabled;

        return $data;
    }
}
