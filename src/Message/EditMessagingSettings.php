<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\FeatureRequestEmails;

readonly final class EditMessagingSettings
{
    public function __construct(
        public string $playerId,
        public bool $allowDirectMessages,
        public bool $emailNotificationsEnabled,
        public EmailNotificationFrequency $emailNotificationFrequency,
        public bool $newsletterEnabled,
        public bool $resultEmailsEnabled,
        /** Null = field not shown (feature-flagged), keep the stored preference. */
        public null|ContentDigestFrequency $contentDigestFrequency = null,
        /** Null = not on the form, keep the stored preference */
        public null|FeatureRequestEmails $featureRequestEmails = null,
    ) {
    }
}
