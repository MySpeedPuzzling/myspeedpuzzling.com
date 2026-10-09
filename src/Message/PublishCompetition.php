<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A one-time event's or an edition's draft flag goes off (docs/features/organizations/README.md "Drafts"): a pending
 * one-time event enters the approval queue then; official results published while it was hidden are told once it is
 * public.
 */
readonly final class PublishCompetition
{
    public function __construct(
        public string $competitionId,
        // The admins get the "submitted" e-mail when a pending item is published - never when an admin publishes it
        public bool $notifyAdmin = true,
    ) {
    }
}
