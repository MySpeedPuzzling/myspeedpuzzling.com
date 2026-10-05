<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may "Suggest another name" (docs/features/puzzle-names/README.md): every signed-in player while the kill switch
 * PUZZLE_NAME_SUGGESTIONS_PUBLIC is on (docs/features/feature_flags.md), moderators and admins always - their names
 * are applied at once. The puzzle page's menu (Twig global `puzzle_name_suggestions`) and the controller ask here.
 */
readonly final class PuzzleNameSuggestions
{
    public function __construct(
        private Security $security,
        private bool $puzzleNameSuggestionsPublic,
    ) {
    }

    public function isOpen(): bool
    {
        if ($this->security->isGranted('IS_AUTHENTICATED_REMEMBERED') === false) {
            return false;
        }

        return $this->puzzleNameSuggestionsPublic || $this->security->isGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS);
    }
}
