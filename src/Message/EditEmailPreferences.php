<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\FeatureRequestEmails;

readonly final class EditEmailPreferences
{
    public function __construct(
        public string $playerId,
        public bool $newsletterEnabled,
        public bool $emailNotificationsEnabled,
        public EmailNotificationFrequency $emailNotificationFrequency,
        public bool $resultEmailsEnabled,
        /** The activity digest; null = not shown on the page, keep the stored value */
        public null|ContentDigestFrequency $contentDigestFrequency = null,
        /** Null = not on the form, keep the stored preference */
        public null|FeatureRequestEmails $featureRequestEmails = null,
    ) {
    }
}
