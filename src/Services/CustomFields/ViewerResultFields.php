<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use Symfony\Component\HttpFoundation\Request;

/**
 * The signed-in player's fields for results, for the pages nearly everybody opens without having any (the add form,
 * the result detail): one statement, and "has none" is remembered in the player's session for two minutes.
 *
 * Why the memo exists: the add form's statement budget is pinned exactly (PuzzleAddPageQueryBudgetTest - "a higher
 * number is a bug to explain"), and a feature most players never use must not put a statement on every add form
 * and every result detail. With the memo a player without fields pays one statement per two minutes of use, a
 * player with fields pays it every time (docs/features/custom-fields.md "What it costs").
 *
 * Its one downside: a first field made on another device shows up here up to two minutes late. On this device at
 * once - the manage page clears the memo on every change and whenever it is opened with fields.
 *
 * Callers pass the signed-in player's id - AddFormCustomFields and OwnCustomValuesOnResults resolve it themselves.
 */
readonly final class ViewerResultFields
{
    private const string SESSION_KEY = 'my_fields.no_result_fields';
    private const int REMEMBER_NONE_SECONDS = 120;

    public function __construct(
        private GetCustomFields $getCustomFields,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<CustomFieldOverview> for results, not archived, in the player's order
     */
    public function of(Request $request, string $playerId): array
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $now = $this->clock->now()->getTimestamp();
        $memo = $session?->get(self::SESSION_KEY);

        if (
            is_array($memo)
            && ($memo['player'] ?? null) === $playerId
            && is_int($memo['until'] ?? null)
            && $memo['until'] > $now
            // A clock that went back must not make the memo last
            && $memo['until'] <= $now + self::REMEMBER_NONE_SECONDS
        ) {
            return [];
        }

        $fields = $this->getCustomFields->usableOnResults($playerId);

        if ($fields === []) {
            $session?->set(self::SESSION_KEY, ['player' => $playerId, 'until' => $now + self::REMEMBER_NONE_SECONDS]);
        } elseif ($memo !== null) {
            $session->remove(self::SESSION_KEY);
        }

        return $fields;
    }

    public function fieldsChanged(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }
}
