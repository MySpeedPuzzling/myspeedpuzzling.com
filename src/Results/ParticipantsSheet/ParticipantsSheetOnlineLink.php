<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

/**
 * A participant's personal link on the participants sheet (docs/features/online-competitions/README.md §5.4) - only on
 * an event with a round using online timing, every participant then has one of these: `link` null = no link (yet), or
 * none any more (removed). The sheet shows it read only; links are made and replaced on the personal links page.
 */
readonly final class ParticipantsSheetOnlineLink implements \JsonSerializable
{
    public function __construct(
        public null|string $link,
    ) {
    }

    /**
     * @return array{link: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'link' => $this->link,
        ];
    }
}
