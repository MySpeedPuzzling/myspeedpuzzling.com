<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Drafts;

use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The text of a refused Unpublish (drafts_core.flash.cannot_unpublish): "It cannot go back to draft: people have joined
 * it, it has official results."
 */
readonly final class CannotUnpublishMessage
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function of(CannotUnpublish $exception): string
    {
        $reasons = array_map(
            fn (UnpublishBlocker $blocker): string => $this->translator->trans($blocker->translationKey()),
            $exception->blockers,
        );

        // The joiner is the language's own (Japanese lists with 、)
        return $this->translator->trans('drafts_core.flash.cannot_unpublish', [
            '%reasons%' => implode($this->translator->trans('drafts_core.blocker_separator'), $reasons),
        ]);
    }
}
