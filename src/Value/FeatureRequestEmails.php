<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which feature request e-mails a player gets (docs/features/feature-requests.md) - when a request is completed or
 * declined. Upvoted (the default) = the requests the player suggested or upvoted, All = every request, None = nothing,
 * not even about the player's own requests.
 */
enum FeatureRequestEmails: string
{
    case All = 'all';
    case Upvoted = 'upvoted';
    case None = 'none';

    public function translationKey(): string
    {
        return 'feature_request_emails.' . $this->value;
    }
}
