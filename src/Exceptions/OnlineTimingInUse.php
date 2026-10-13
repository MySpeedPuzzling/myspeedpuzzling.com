<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A change refused because a round uses online timing, which works only for a solo round of an online event
 * (OnlineTimingAvailability, docs/features/online-competitions/): a round changed away from solo, an event or a series
 * changed to in person, a round or an edition moved into an in-person event or series. Refused rather than turning
 * online timing off by itself - participants may hold their links and be competing right now. `reason` is a key of
 * online_rounds.settings.in_use.*, `rounds` the names of the rounds using it (for the message). Nothing was changed.
 */
final class OnlineTimingInUse extends ConflictHttpException
{
    public const string ROUND_CATEGORY = 'round_category';
    public const string EVENT_IN_PERSON = 'event_in_person';
    public const string SERIES_IN_PERSON = 'series_in_person';
    public const string ROUND_MOVE = 'round_move';
    public const string EDITION_MOVE = 'edition_move';

    /**
     * @param list<string> $rounds
     */
    public function __construct(
        readonly public string $reason,
        readonly public array $rounds,
    ) {
        parent::__construct(sprintf('Refused - online timing is in use (%s: %s).', $reason, implode(', ', $rounds)));
    }

    public function translationKey(): string
    {
        return 'online_rounds.settings.in_use.' . $this->reason;
    }

    /**
     * @return array{'%rounds%': string}
     */
    public function translationParameters(): array
    {
        return ['%rounds%' => implode(', ', $this->rounds)];
    }
}
