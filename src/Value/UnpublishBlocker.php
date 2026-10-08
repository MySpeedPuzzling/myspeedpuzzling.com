<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why an event (or a series, through any of its editions) cannot go back to draft
 * (docs/features/organizations/README.md "Drafts", UnpublishBlockers).
 */
enum UnpublishBlocker: string
{
    // Somebody joined it (a participant row that is not deleted)
    case Participants = 'participants';
    // An entry of one of its rounds holds an official result or a qualified mark
    case Results = 'results';
    // A solving time is linked to it or to one of its rounds
    case SolvingTimes = 'solving_times';

    public function translationKey(): string
    {
        return 'drafts_core.blocker.' . $this->value;
    }
}
