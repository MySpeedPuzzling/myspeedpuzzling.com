<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which members-only teaser opened the members modal (docs/features/navigation README §6 rule 5, TEASE-01): the
 * teaser's `data-members-placement`, which the modal passes on (members_modal_controller.js) - in the `members_modal`
 * beacon when it opens and as the free-trial form's `placement` field. Measured only, never stored: the stored
 * FreeTrialSource stays `members_modal` (implementation plan decision 12, R12).
 *
 * A new teaser gets a case here and the attribute on its lock - the script accepts any `word.word` id, the server
 * only these.
 */
enum MembersPlacement: string
{
    // The locked tier chip on the player card (players/_card.html.twig)
    case CardTier = 'card.tier';

    // The locked tier chip in the player header (components/PlayerHeader.html.twig)
    case ProfileTier = 'profile.tier';

    // The locked members filters of a player's Results (player/results/_filters.html.twig, QA-04 R14)
    case ResultsFilters = 'results.filters';

    // A locked members sort of a player's Results (player/results/_frame.html.twig, QA-04 R14)
    case ResultsSort = 'results.sort';
}
