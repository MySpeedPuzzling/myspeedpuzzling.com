<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Query\GetSuggestedPlayers;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\SuggestedPlayer;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Suggested for you (docs/features/players-page/README.md, stream S6): people to follow with a visible reason, for
 * signed-in players only - guests get nothing rendered and no query. Favorite and Compare are the same endpoints as
 * the player header (a favorite leaves the suggestions on the next render, it is excluded).
 *
 * Up to GetSuggestedPlayers::LIMIT people are rendered; below the lg breakpoint only the first MOBILE_VISIBLE show.
 */
#[AsTwigComponent]
final class Suggestions
{
    public const int MOBILE_VISIBLE = 4;

    /** @var list<SuggestedPlayer> */
    public array $suggestions = [];

    private null|PlayerProfile $viewer = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetSuggestedPlayers $getSuggestedPlayers,
    ) {
    }

    public function mount(): void
    {
        $this->viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->viewer !== null) {
            $this->suggestions = $this->getSuggestedPlayers->forViewer($this->viewer->playerId);
        }
    }

    public function comparisonRef(string $playerId): string
    {
        return ComparisonSubjectRef::player($playerId)->toString();
    }

    public function isInComparison(string $playerId): bool
    {
        return $this->viewer !== null
            && $this->viewer->comparisonLineUp->contains(ComparisonSubjectRef::player($playerId));
    }
}
