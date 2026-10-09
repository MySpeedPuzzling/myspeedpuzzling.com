<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Drafts;

use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The text of a refused Unpublish (drafts_core.flash.cannot_unpublish): "It cannot go back to draft: people have joined
 * it, it has official results." - with the item's name when given ("Harbor Club Meet 1 cannot go back to draft: …"),
 * as a flash shown on another page ("You organize") must say which item it means.
 */
readonly final class CannotUnpublishMessage
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function of(CannotUnpublish $exception, null|string $name = null): string
    {
        $reasons = array_map(
            fn (UnpublishBlocker $blocker): string => $this->translator->trans($blocker->translationKey()),
            $exception->blockers,
        );

        // The joiner is the language's own (Japanese lists with 、)
        $joined = implode($this->translator->trans('drafts_core.blocker_separator'), $reasons);

        if ($name !== null) {
            return $this->translator->trans('drafts_core.flash.cannot_unpublish_named', ['%name%' => $name, '%reasons%' => $joined]);
        }

        return $this->translator->trans('drafts_core.flash.cannot_unpublish', ['%reasons%' => $joined]);
    }
}
