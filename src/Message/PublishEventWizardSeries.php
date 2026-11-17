<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Publish" on the series path's Review (W-creation-wizard-research.md §3.9, W3): the series (PublishCompetitionSeries -
 * its editions keep their own draft flags, P8) and every draft edition of it (PublishCompetition each - the dates the
 * wizard made with the draft series) in one transaction. An orchestration of existing messages.
 */
readonly final class PublishEventWizardSeries
{
    public function __construct(
        public string $seriesId,
        // The admins' "submitted" e-mail for the series - never when an admin publishes it (an edition e-mails nobody)
        public bool $notifyAdmin = true,
    ) {
    }
}
