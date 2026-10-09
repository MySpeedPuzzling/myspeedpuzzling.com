<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A series' draft flag goes off (docs/features/organizations/README.md "Drafts") - its editions keep their own (P8). A
 * pending series enters the approval queue then; official results published while it was hidden are told once public.
 */
readonly final class PublishCompetitionSeries
{
    public function __construct(
        public string $seriesId,
        // The admins get the "submitted" e-mail when a pending item is published - never when an admin publishes it
        public bool $notifyAdmin = true,
    ) {
    }
}
